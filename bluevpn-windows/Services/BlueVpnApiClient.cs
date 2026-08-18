using System.Net.Http;
using System.Net.Http.Headers;
using System.Net.Http.Json;
using System.Text;
using System.Text.Json;
using BlueVPN.Windows.Models;

namespace BlueVPN.Windows.Services;

public sealed class BlueVpnApiClient : IDisposable
{
    private readonly HttpClient _http;
    private readonly AppSettings _settings;
    private string _token = "";
    private string _otpChallengeId = "";

    public BlueVpnApiClient(AppSettings settings)
    {
        _settings = settings;
        _http = new HttpClient(new HttpClientHandler
        {
            AllowAutoRedirect = true,
            AutomaticDecompression = System.Net.DecompressionMethods.All
        })
        {
            BaseAddress = new Uri(settings.ApiBaseUrl.TrimEnd('/') + "/"),
            Timeout = TimeSpan.FromSeconds(20)
        };
        _http.DefaultRequestHeaders.UserAgent.ParseAdd($"BlueVPN-Windows/{settings.Version}");
        _http.DefaultRequestHeaders.Add("X-BlueVPN-Platform", "windows");
        _http.DefaultRequestHeaders.Add("X-Device-Id", DeviceIdentity.GetOrCreate());
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

    public void Logout()
    {
        _token = "";
        _otpChallengeId = "";
        _http.DefaultRequestHeaders.Authorization = null;
    }

    private void ApplyToken(string token)
    {
        _token = token?.Trim() ?? "";
        if (string.IsNullOrWhiteSpace(_token))
            throw new InvalidOperationException("توکن ورود از سرور دریافت نشد.");
        _http.DefaultRequestHeaders.Authorization = new AuthenticationHeaderValue("Bearer", _token);
    }

    private void EnsureAuth()
    {
        if (!IsAuthenticated) throw new InvalidOperationException("ابتدا وارد حساب شوید.");
    }

    private async Task<T> GetAsync<T>(string path, CancellationToken ct)
    {
        using var response = await _http.GetAsync(path, ct);
        return await ReadJsonAsync<T>(response, ct);
    }

    private async Task<T> PostAsync<T>(string path, object body, CancellationToken ct)
    {
        using var response = await _http.PostAsJsonAsync(path, body, AppSettings.JsonOptions(), ct);
        return await ReadJsonAsync<T>(response, ct);
    }

    private async Task<T> ReadJsonAsync<T>(HttpResponseMessage response, CancellationToken ct)
    {
        var text = await response.Content.ReadAsStringAsync(ct);
        if (!response.IsSuccessStatusCode)
            throw new InvalidOperationException(ReadError(text, (int)response.StatusCode));

        return JsonSerializer.Deserialize<T>(text, AppSettings.JsonOptions())
            ?? throw new InvalidOperationException("پاسخ نامعتبر از سرور BlueVPN.");
    }

    private async Task<string> GetRawAsync(string pathOrUrl, CancellationToken ct)
    {
        using var request = new HttpRequestMessage(HttpMethod.Get, pathOrUrl);
        using var response = await _http.SendAsync(request, HttpCompletionOption.ResponseContentRead, ct);
        var text = await response.Content.ReadAsStringAsync(ct);
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

    public void Dispose() => _http.Dispose();
}
