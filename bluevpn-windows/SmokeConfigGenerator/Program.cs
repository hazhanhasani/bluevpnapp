using BlueVPN.Windows.Services;

var output = args.Length > 0 ? Path.GetFullPath(args[0]) : Path.Combine(Path.GetTempPath(), "bluevpn-generated-smoke");
Directory.CreateDirectory(output);
var settings = new AppSettings();
File.WriteAllText(
    Path.Combine(output, "singbox-v2rayn-generated.json"),
    V2RayNTunConfigBuilder.Build(settings, XrayConfigBuilder.LocalSocksPort, "example.com", new[] { "203.0.113.10" }));
File.WriteAllText(
    Path.Combine(output, "singbox-warp-generated.json"),
    SingBoxWarpConfigBuilder.Build(settings, 1819));
Console.WriteLine(output);
