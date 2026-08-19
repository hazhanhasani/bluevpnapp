using System.Net;
using System.Net.Http;
using System.Net.Http.Headers;
using System.Security.Authentication;
using System.Text;
using System.Text.Json;
using BlueVPN.Windows.Models;

namespace BlueVPN.Windows.Services;

public sealed class BlueVpnApiClient : IDisposable
{
    private readonly HttpClient _directHttp;
    private readonly HttpClient _systemProxyHttp;
    private readonly AppSettings _settings;
    private string _token = "";
    private string _otpChallengeId = "";

    public BlueVpnApiClient(AppSettings settings)
    {
        _settings = settings;
        var baseAddress = new Uri(settings.ApiBaseUrl.TrimEnd('/') + "/");

        // A VPN client must not depend on a stale Windows system proxy. Previous
        // v2rayN/other proxy clients can leave WinINET proxy state pointing to a
        // local port that is no longer listening; HttpClient then surfaces this as
        // an SSL/TLS failure while ordinary direct Internet access is healthy.
        // Prefer a clean direct control-plane path, then retry once through the
        // Windows proxy for networks where a legitimate system proxy is required.
        _directHttp = CreateHttpClient(baseAddress, settings.Version, useSystemProxy: false);
        _systemProxyHttp = CreateHttpClient(baseAddress, settings.Version, useSystemProxy: true);
    }

    public bool IsAuthenticated => !string.IsNullOrWhiteSpace(_token);
    public string OtpChallengeId => _otpChallengeId;

    public async Task<AuthResponse> LoginAsync(string email, string password, CancellationToken ct = default)
    {
        var response = await PostAsync<AuthResponse>("wp-json/bluevpn/v1/auth/login", new
        {
            email,
            password,
            device_id = DeviceIdentity.GetOrCreate(),
            device_name = DeviceIdentity.FriendlyName
        }, ct);
        ApplyToken(response.Token);
        return response;
    }

    public async Task<AuthResponse> RegisterAsync(string email, string password, CancellationToken ct = default)
    {
        var response = await PostAsync<AuthResponse>("wp-json/bluevpn/v1/auth/register", new
        {
            email,
            password,
            device_id = DeviceIdentity.GetOrCreate(),
            device_name = DeviceIdentity.FriendlyName
        }, ct);
        ApplyToken(response.Token);
        return response;
    }

    public async Task<OtpRequestResponse> RequestOtpAsync(string phone, CancellationToken ct = default)
    {
        var response = await PostAsync<OtpRequestResponse>("wp-json/bluevpn/v1/auth/otp/request", new
        {
            phone,
            device_id = DeviceIdentity.GetOrCreate()
        }, ct);
        _otpChallengeId = response.ChallengeId;
        return response;
    }

    public async Task<AuthResponse> VerifyOtpAsync(string phone, string code, CancellationToken ct = default)
    {
        if (string.IsNullOrWhiteSpace(_otpChallengeId))
            throw new InvalidOperationException("ابتدا کد پیامک را درخواست کنید.");

        var response = await PostAsync<AuthResponse>("wp-json/bluevpn/v1/auth/otp/verify", new
        {
            phone,
            challenge_id = _otpChallengeId,
            code,
            device_id = DeviceIdentity.GetOrCreate(),
            device_name = DeviceIdentity.FriendlyName
        }, ct);
        ApplyToken(response.Token);
        return response;
    }

    public async Task<Account> GetAccountAsync(CancellationToken ct = default)
    {
        EnsureAuth();
        var result = await GetAsync<AccountResponse>("wp-json/bluevpn/v1/account", ct);
        return result.Account ?? throw new InvalidOperationException("اطلاعات حساب دریافت نشد.");
    }

    public async Task<IReadOnlyList<Plan>> GetPlansAsync(CancellationToken ct = default)
    {
        if (!IsAuthenticated) return [];
        var result = await GetAsync<PlansResponse>("wp-json/bluevpn/v1/plans", ct);
        return result.Plans;
    }

    public async Task<string> GetPremiumSubscriptionAsync(Account account, CancellationToken ct = default)
    {
        if (!account.Subscription.Active || string.IsNullOrWhiteSpace(account.Subscription.Url))
            throw new InvalidOperationException("اشتراک ویژه فعال برای این حساب وجود ندارد.");
        return await GetRawAsync(account.Subscription.Url, ct);
    }

    public Task<string> GetFreeSubscriptionAsync(CancellationToken ct = default) =>
        GetRawAsync(_settings.FreeSubscriptionPath, ct);

    public async Task<MobileConfigResponse> GetMobileConfigAsync(CancellationToken ct = default)
    {
        var path = _settings.MobileConfigPath.TrimStart('/');
        return await GetAsync<MobileConfigResponse>(path, ct);
    }

    public async Task<WindowsUpdateResponse> GetWindowsUpdateAsync(string currentVersion, string architecture, CancellationToken ct = default)
    {
        var path = _settings.WindowsUpdatePath.TrimStart('/');
        var sep = path.Contains('?') ? "&" : "?";
        path += sep + "current_version=" + Uri.EscapeDataString(currentVersion) + "&arch=" + Uri.EscapeDataString(architecture);
        return await GetAsync<WindowsUpdateResponse>(path, ct);
    }

    public async Task LogoutAsync(CancellationToken ct = default)
    {
        // Logout is server-authoritative: release the session/device slot before
        // discarding the local bearer token. Local cleanup still happens even if
        // the network is unavailable so the UI never stays signed in.
        try
        {
            if (IsAuthenticated)
            {
                using var response = await SendWithTransportFallbackAsync(() =>
                {
                    return new HttpRequestMessage(HttpMethod.Post, "wp-json/bluevpn/v1/auth/logout")
                    {
                        Content = new StringContent("{}", Encoding.UTF8, "application/json")
                    };
                }, ct).ConfigureAwait(false);
                _ = await response.Content.ReadAsStringAsync(ct).ConfigureAwait(false);
            }
        }
        finally
        {
            ClearLocalSession();
        }
    }

    public void ClearLocalSession()
    {
        _token = "";
        _otpChallengeId = "";
        SetAuthorization(null);
    }

    private static HttpClient CreateHttpClient(Uri baseAddress, string version, bool useSystemProxy)
    {
        var handler = new HttpClientHandler
        {
            AllowAutoRedirect = true,
            AutomaticDecompression = DecompressionMethods.All,
            UseProxy = useSystemProxy,
            Proxy = useSystemProxy ? WebRequest.DefaultWebProxy : null
        };

        var http = new HttpClient(handler)
        {
            BaseAddress = baseAddress,
            Timeout = TimeSpan.FromSeconds(20),
            DefaultRequestVersion = HttpVersion.Version11,
            DefaultVersionPolicy = HttpVersionPolicy.RequestVersionOrLower
        };
        http.DefaultRequestHeaders.UserAgent.ParseAdd($"BlueVPN-Windows/{version}");
        http.DefaultRequestHeaders.Add("X-BlueVPN-Platform", "windows");
        http.DefaultRequestHeaders.Add("X-Device-Id", DeviceIdentity.GetOrCreate());
        return http;
    }

    private void ApplyToken(string token)
    {
        _token = token?.Trim() ?? "";
        if (string.IsNullOrWhiteSpace(_token))
            throw new InvalidOperationException("توکن ورود از سرور دریافت نشد.");
        SetAuthorization(new AuthenticationHeaderValue("Bearer", _token));
    }

    private void SetAuthorization(AuthenticationHeaderValue? value)
    {
        _directHttp.DefaultRequestHeaders.Authorization = value;
        _systemProxyHttp.DefaultRequestHeaders.Authorization = value;
    }

    private void EnsureAuth()
    {
        if (!IsAuthenticated) throw new InvalidOperationException("ابتدا وارد حساب شوید.");
    }

    private async Task<T> GetAsync<T>(string path, CancellationToken ct)
    {
        using var response = await SendWithTransportFallbackAsync(
            () => new HttpRequestMessage(HttpMethod.Get, path), ct).ConfigureAwait(false);
        return await ReadJsonAsync<T>(response, ct).ConfigureAwait(false);
    }

    private async Task<T> PostAsync<T>(string path, object body, CancellationToken ct)
    {
        var json = JsonSerializer.Serialize(body, AppSettings.JsonOptions());
        using var response = await SendWithTransportFallbackAsync(() =>
        {
            var request = new HttpRequestMessage(HttpMethod.Post, path)
            {
                Content = new StringContent(json, Encoding.UTF8, "application/json")
            };
            return request;
        }, ct).ConfigureAwait(false);
        return await ReadJsonAsync<T>(response, ct).ConfigureAwait(false);
    }

    private async Task<HttpResponseMessage> SendWithTransportFallbackAsync(Func<HttpRequestMessage> requestFactory, CancellationToken ct)
    {
        Exception? directError = null;
        try
        {
            using var request = requestFactory();
            return await _directHttp.SendAsync(request, HttpCompletionOption.ResponseContentRead, ct).ConfigureAwait(false);
        }
        catch (OperationCanceledException) when (ct.IsCancellationRequested)
        {
            throw;
        }
        catch (Exception ex) when (IsRetryableTransportFailure(ex))
        {
            directError = ex;
        }

        try
        {
            using var request = requestFactory();
            return await _systemProxyHttp.SendAsync(request, HttpCompletionOption.ResponseContentRead, ct).ConfigureAwait(false);
        }
        catch (OperationCanceledException) when (ct.IsCancellationRequested)
        {
            throw;
        }
        catch (Exception ex) when (IsRetryableTransportFailure(ex))
        {
            throw FriendlyTransportException(directError, ex);
        }
    }

    private static bool IsRetryableTransportFailure(Exception ex) =>
        ex is HttpRequestException || ex is TaskCanceledException || ex is System.IO.IOException || ex is AuthenticationException;

    private static InvalidOperationException FriendlyTransportException(Exception? first, Exception second)
    {
        var tls = HasTlsFailure(first) || HasTlsFailure(second);
        var code = tls ? "CONTROL_PLANE_TLS" : "CONTROL_PLANE_NETWORK";
        var message = tls
            ? "ارتباط امن با سرور BlueVPN برقرار نشد. مسیر مستقیم و تنظیمات شبکه ویندوز هر دو بررسی شدند؛ تاریخ و ساعت ویندوز و دسترسی اینترنت را بررسی کنید."
            : "ارتباط با سرور BlueVPN برقرار نشد. مسیر مستقیم و تنظیمات شبکه ویندوز هر دو امتحان شدند.";
        return new InvalidOperationException($"{message} (کد: {code})", new AggregateException(first ?? second, second));
    }

    private static bool HasTlsFailure(Exception? ex)
    {
        for (var current = ex; current is not null; current = current.InnerException)
        {
            if (current is AuthenticationException) return true;
            var text = current.Message;
            if (text.Contains("SSL", StringComparison.OrdinalIgnoreCase) ||
                text.Contains("TLS", StringComparison.OrdinalIgnoreCase) ||
                text.Contains("certificate", StringComparison.OrdinalIgnoreCase)) return true;
        }
        return false;
    }

    private async Task<T> ReadJsonAsync<T>(HttpResponseMessage response, CancellationToken ct)
    {
        var text = await response.Content.ReadAsStringAsync(ct).ConfigureAwait(false);
        if (!response.IsSuccessStatusCode)
            throw new InvalidOperationException(ReadError(text, (int)response.StatusCode));

        return JsonSerializer.Deserialize<T>(text, AppSettings.JsonOptions())
            ?? throw new InvalidOperationException("پاسخ نامعتبر از سرور BlueVPN.");
    }

    private async Task<string> GetRawAsync(string pathOrUrl, CancellationToken ct)
    {
        using var response = await SendWithTransportFallbackAsync(
            () => new HttpRequestMessage(HttpMethod.Get, pathOrUrl), ct).ConfigureAwait(false);
        var text = await response.Content.ReadAsStringAsync(ct).ConfigureAwait(false);
        if (!response.IsSuccessStatusCode)
            throw new InvalidOperationException(ReadError(text, (int)response.StatusCode));
        return text;
    }

    private static string ReadError(string text, int status)
    {
        try
        {
            using var doc = JsonDocument.Parse(text);
            var root = doc.RootElement;
            if (root.TryGetProperty("detail", out var detail) && detail.ValueKind == JsonValueKind.Object)
            {
                if (detail.TryGetProperty("message", out var msg)) return msg.GetString() ?? $"HTTP {status}";
            }
            if (root.TryGetProperty("message", out var message)) return message.GetString() ?? $"HTTP {status}";
        }
        catch { }
        return $"خطای سرور BlueVPN (HTTP {status})";
    }

    public void Dispose()
    {
        _directHttp.Dispose();
        _systemProxyHttp.Dispose();
    }
}
