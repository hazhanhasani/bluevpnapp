namespace BlueVPN.Windows.Models;

public sealed class ProxyEndpoint
{
    public string Protocol { get; init; } = "";
    public string Name { get; init; } = "";
    public string Host { get; init; } = "";
    public int Port { get; init; }
    public string UserId { get; init; } = "";
    public string Password { get; init; } = "";
    public string Method { get; init; } = "";
    public int AlterId { get; init; }
    public string Encryption { get; init; } = "none";
    public string Flow { get; init; } = "";
    public string Transport { get; init; } = "tcp";
    public string Security { get; init; } = "none";
    public string Sni { get; init; } = "";
    public string HostHeader { get; init; } = "";
    public string Path { get; init; } = "";
    public string ServiceName { get; init; } = "";
    public string Fingerprint { get; init; } = "chrome";
    public string PublicKey { get; init; } = "";
    public string ShortId { get; init; } = "";
    public string SpiderX { get; init; } = "";
    public string Mode { get; init; } = "";
    public IReadOnlyList<string> Alpn { get; init; } = [];
    public int ProbeLatencyMs { get; set; } = int.MaxValue;
    public int ProbeJitterMs { get; set; } = int.MaxValue;
    public int ProbeSuccessCount { get; set; }
    public int ProbeSampleCount { get; set; }

    // Raw subscription remarks are internal metadata only. Any UI/progress
    // surface that asks for DisplayName receives a BlueVPN-owned label.
    public string DisplayName => "BlueVPN • مسیر امن";

    public string DiagnosticName => string.IsNullOrWhiteSpace(Name)
        ? $"{Protocol.ToUpperInvariant()} • {Host}:{Port}"
        : Name;
}
