using System.IO;
using System.Runtime.InteropServices;
using System.Text;
using System.Text.Json;
using BlueVPN.Windows.Models;

namespace BlueVPN.Windows.Services;

/// <summary>
/// Persists the Windows login session for the current Windows user only.
/// The payload is encrypted with Windows DPAPI (CryptProtectData), so the
/// bearer token is never written to disk in plaintext and cannot be reused
/// by another Windows account.
/// </summary>
public sealed class WindowsSessionSnapshot
{
    public string Token { get; set; } = "";
    public Account? Account { get; set; }
    public string DeviceId { get; set; } = "";
    public DateTimeOffset SavedAt { get; set; } = DateTimeOffset.UtcNow;
}

public static class WindowsSessionStore
{
    private const int CryptProtectUiForbidden = 0x1;
    private const string SessionFileName = "session.dat";

    [StructLayout(LayoutKind.Sequential)]
    private struct DataBlob
    {
        public int Size;
        public IntPtr Data;
    }

    [DllImport("crypt32.dll", CharSet = CharSet.Unicode, SetLastError = true)]
    [return: MarshalAs(UnmanagedType.Bool)]
    private static extern bool CryptProtectData(
        ref DataBlob dataIn,
        string? description,
        IntPtr optionalEntropy,
        IntPtr reserved,
        IntPtr promptStruct,
        int flags,
        out DataBlob dataOut);

    [DllImport("crypt32.dll", CharSet = CharSet.Unicode, SetLastError = true)]
    [return: MarshalAs(UnmanagedType.Bool)]
    private static extern bool CryptUnprotectData(
        ref DataBlob dataIn,
        IntPtr description,
        IntPtr optionalEntropy,
        IntPtr reserved,
        IntPtr promptStruct,
        int flags,
        out DataBlob dataOut);

    [DllImport("kernel32.dll", SetLastError = true)]
    private static extern IntPtr LocalFree(IntPtr memory);

    private static string SessionPath
    {
        get
        {
            var dir = Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData), "BlueVPN");
            Directory.CreateDirectory(dir);
            return Path.Combine(dir, SessionFileName);
        }
    }

    public static WindowsSessionSnapshot? Load()
    {
        try
        {
            if (!OperatingSystem.IsWindows() || !File.Exists(SessionPath)) return null;
            var encrypted = File.ReadAllBytes(SessionPath);
            if (encrypted.Length == 0) return null;
            var json = Encoding.UTF8.GetString(Unprotect(encrypted));
            var snapshot = JsonSerializer.Deserialize<WindowsSessionSnapshot>(json, AppSettings.JsonOptions());
            if (snapshot is null || string.IsNullOrWhiteSpace(snapshot.Token)) return null;
            if (!string.Equals(snapshot.DeviceId, DeviceIdentity.GetOrCreate(), StringComparison.OrdinalIgnoreCase))
            {
                Delete();
                return null;
            }
            snapshot.Token = snapshot.Token.Trim();
            return snapshot;
        }
        catch
        {
            // A corrupt/stale encrypted session must never prevent BlueVPN from starting.
            Delete();
            return null;
        }
    }

    public static void Save(string token, Account? account)
    {
        token = token?.Trim() ?? "";
        if (string.IsNullOrWhiteSpace(token))
        {
            Delete();
            return;
        }
        if (!OperatingSystem.IsWindows()) return;

        var payload = new WindowsSessionSnapshot
        {
            Token = token,
            Account = account,
            DeviceId = DeviceIdentity.GetOrCreate(),
            SavedAt = DateTimeOffset.UtcNow
        };
        var json = JsonSerializer.Serialize(payload, AppSettings.JsonOptions());
        var encrypted = Protect(Encoding.UTF8.GetBytes(json));
        var path = SessionPath;
        var temp = path + ".tmp";
        File.WriteAllBytes(temp, encrypted);
        File.Move(temp, path, overwrite: true);
    }

    public static void Delete()
    {
        try
        {
            var path = SessionPath;
            if (File.Exists(path)) File.Delete(path);
            var temp = path + ".tmp";
            if (File.Exists(temp)) File.Delete(temp);
        }
        catch { }
    }

    private static byte[] Protect(byte[] input) => Transform(input, protect: true);
    private static byte[] Unprotect(byte[] input) => Transform(input, protect: false);

    private static byte[] Transform(byte[] input, bool protect)
    {
        var inputPointer = Marshal.AllocHGlobal(input.Length);
        DataBlob output = default;
        try
        {
            Marshal.Copy(input, 0, inputPointer, input.Length);
            var inputBlob = new DataBlob { Size = input.Length, Data = inputPointer };
            var ok = protect
                ? CryptProtectData(ref inputBlob, "BlueVPN Windows session", IntPtr.Zero, IntPtr.Zero, IntPtr.Zero, CryptProtectUiForbidden, out output)
                : CryptUnprotectData(ref inputBlob, IntPtr.Zero, IntPtr.Zero, IntPtr.Zero, IntPtr.Zero, CryptProtectUiForbidden, out output);
            if (!ok) throw new InvalidOperationException($"Windows DPAPI failed ({Marshal.GetLastWin32Error()}).");
            var result = new byte[output.Size];
            Marshal.Copy(output.Data, result, 0, output.Size);
            return result;
        }
        finally
        {
            Marshal.FreeHGlobal(inputPointer);
            if (output.Data != IntPtr.Zero) LocalFree(output.Data);
        }
    }
}
