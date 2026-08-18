using System.IO;
namespace BlueVPN.Windows.Services;

public static class DeviceIdentity
{
    public static string GetOrCreate()
    {
        var dir = Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData), "BlueVPN");
        Directory.CreateDirectory(dir);
        var path = Path.Combine(dir, "device-id.txt");
        if (File.Exists(path))
        {
            var existing = File.ReadAllText(path).Trim();
            if (Guid.TryParse(existing, out _)) return existing;
        }

        var id = Guid.NewGuid().ToString("D");
        File.WriteAllText(path, id);
        return id;
    }

    public static string FriendlyName => $"Windows • {Environment.MachineName}";
}
