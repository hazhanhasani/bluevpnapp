using System.IO;

namespace BlueVPN.Windows.Services;

public sealed class InsufficientUpdateSpaceException : IOException
{
    public string DriveName { get; }
    public long RequiredBytes { get; }
    public long AvailableBytes { get; }

    public InsufficientUpdateSpaceException(string driveName, long requiredBytes, long availableBytes)
        : base(BuildMessage(driveName, requiredBytes, availableBytes))
    {
        DriveName = driveName;
        RequiredBytes = requiredBytes;
        AvailableBytes = availableBytes;
    }

    private static string BuildMessage(string driveName, long requiredBytes, long availableBytes)
    {
        static string Gb(long value) => $"{Math.Max(0, value) / 1024d / 1024d / 1024d:0.0}";
        return $"فضای کافی برای بروزرسانی BlueVPN وجود ندارد. درایو {driveName} فقط {Gb(availableBytes)} گیگابایت فضای آزاد دارد، اما حداقل {Gb(requiredBytes)} گیگابایت لازم است. چند فایل غیرضروری را حذف کن و دوباره بروزرسانی را بزن.";
    }
}

public sealed record UpdateStoragePlan(string RootPath, string DestinationPath, long RequiredBytes, long AvailableBytes, bool UsesFallbackDrive);

public static class UpdateStorageManager
{
    private const long MiB = 1024L * 1024L;
    private const long GiB = 1024L * MiB;
    private const long DownloadReserveBytes = 256L * MiB;
    private const long MinimumInstallReserveBytes = 1L * GiB;
    private const long UnknownInstallerBytes = 768L * MiB;

    public static UpdateStoragePlan PrepareAppUpdate(string version, string assetName, long payloadBytes)
    {
        var localRoot = Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData), "BlueVPN", "updates");
        CleanupUpdateCache(localRoot, version);

        var normalizedPayload = payloadBytes > 0 ? payloadBytes : UnknownInstallerBytes;
        var downloadRequired = SafeAdd(normalizedPayload, Math.Max(DownloadReserveBytes, normalizedPayload / 5));
        var installRequired = Math.Max(MinimumInstallReserveBytes, SafeAdd(normalizedPayload, normalizedPayload));

        // The installer normally upgrades the existing installation in-place, so protect
        // that drive as well as the download drive. This prevents a successful download
        // followed by an Inno Setup disk-full failure.
        EnsureFreeSpaceForPath(AppContext.BaseDirectory, installRequired);

        var preferred = Path.Combine(localRoot, version);
        if (HasFreeSpace(preferred, downloadRequired, out var preferredFree))
        {
            Directory.CreateDirectory(preferred);
            return new UpdateStoragePlan(preferred, Path.Combine(preferred, assetName), downloadRequired, preferredFree, false);
        }

        // If LocalAppData is nearly full but another fixed disk exists, use it only when
        // we can actually create a per-user BlueVPN cache there. Installation-drive space
        // was already checked above.
        foreach (var drive in ReadyFixedDrives().OrderByDescending(d => d.AvailableFreeSpace))
        {
            if (drive.AvailableFreeSpace < downloadRequired) continue;
            var fallback = Path.Combine(drive.RootDirectory.FullName, "BlueVPN-Updates", SafeUserFolder(), version);
            if (!TryPrepareDirectory(fallback)) continue;
            return new UpdateStoragePlan(fallback, Path.Combine(fallback, assetName), downloadRequired, drive.AvailableFreeSpace, true);
        }

        var driveName = DriveNameForPath(preferred);
        throw new InsufficientUpdateSpaceException(driveName, downloadRequired, preferredFree);
    }

    public static void PrepareRuntimeUpdate(string targetPath, long compressedBytes)
    {
        var normalized = compressedBytes > 0 ? compressedBytes : 256L * MiB;
        // Runtime update temporarily holds the ZIP and extracted tree together.
        var required = Math.Max(768L * MiB, SafeAdd(SafeAdd(normalized, normalized), DownloadReserveBytes));
        CleanupSiblingTemps(targetPath);
        EnsureFreeSpaceForPath(targetPath, required);
    }

    public static void EnsureFreeSpaceForPath(string path, long requiredBytes)
    {
        if (HasFreeSpace(path, requiredBytes, out var free)) return;
        throw new InsufficientUpdateSpaceException(DriveNameForPath(path), requiredBytes, free);
    }

    public static void CleanupUpdateCache(string root, string keepVersion)
    {
        try
        {
            if (!Directory.Exists(root)) return;
            foreach (var part in Directory.EnumerateFiles(root, "*.part", SearchOption.AllDirectories))
            {
                try
                {
                    var info = new FileInfo(part);
                    var inCurrentVersion = string.Equals(Path.GetFileName(info.DirectoryName), keepVersion, StringComparison.OrdinalIgnoreCase);
                    if (!inCurrentVersion || info.Length == 0 || DateTime.UtcNow - info.LastWriteTimeUtc > TimeSpan.FromHours(12))
                        File.Delete(part);
                }
                catch { }
            }

            foreach (var dir in Directory.EnumerateDirectories(root))
            {
                if (string.Equals(Path.GetFileName(dir), keepVersion, StringComparison.OrdinalIgnoreCase)) continue;
                try { Directory.Delete(dir, true); } catch { }
            }
        }
        catch { }
    }

    private static void CleanupSiblingTemps(string targetPath)
    {
        try
        {
            var parent = Path.GetDirectoryName(targetPath);
            if (string.IsNullOrWhiteSpace(parent) || !Directory.Exists(parent)) return;
            foreach (var file in Directory.EnumerateFiles(parent, "*.part", SearchOption.TopDirectoryOnly))
            {
                try { File.Delete(file); } catch { }
            }
            foreach (var dir in Directory.EnumerateDirectories(parent, "*.tmp", SearchOption.TopDirectoryOnly))
            {
                try { Directory.Delete(dir, true); } catch { }
            }
        }
        catch { }
    }

    private static IEnumerable<DriveInfo> ReadyFixedDrives()
    {
        foreach (var drive in DriveInfo.GetDrives())
        {
            bool ready;
            try { ready = drive.IsReady && drive.DriveType == DriveType.Fixed; }
            catch { ready = false; }
            if (ready) yield return drive;
        }
    }

    private static bool HasFreeSpace(string path, long requiredBytes, out long available)
    {
        available = 0;
        try
        {
            var root = Path.GetPathRoot(Path.GetFullPath(path));
            if (string.IsNullOrWhiteSpace(root)) return false;
            var drive = new DriveInfo(root);
            if (!drive.IsReady) return false;
            available = drive.AvailableFreeSpace;
            return available >= requiredBytes;
        }
        catch { return false; }
    }

    private static string DriveNameForPath(string path)
    {
        try
        {
            var root = Path.GetPathRoot(Path.GetFullPath(path));
            if (!string.IsNullOrWhiteSpace(root)) return root.TrimEnd('\\', '/');
        }
        catch { }
        return "سیستم";
    }

    private static bool TryPrepareDirectory(string path)
    {
        try
        {
            Directory.CreateDirectory(path);
            var probe = Path.Combine(path, $".write-{Guid.NewGuid():N}.tmp");
            File.WriteAllText(probe, "ok");
            File.Delete(probe);
            return true;
        }
        catch { return false; }
    }

    private static string SafeUserFolder()
    {
        var name = Environment.UserName;
        if (string.IsNullOrWhiteSpace(name)) return "user";
        foreach (var ch in Path.GetInvalidFileNameChars()) name = name.Replace(ch, '_');
        return name;
    }

    private static long SafeAdd(long a, long b)
    {
        if (a > long.MaxValue - b) return long.MaxValue;
        return a + b;
    }
}
