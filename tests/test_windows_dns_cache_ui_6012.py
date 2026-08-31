from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]


def test_windows_endpoint_probes_reuse_short_lived_dns_results():
    source = (ROOT / "bluevpn-windows/Services/EndpointSelector.cs").read_text()
    assert "ConcurrentDictionary<string, DnsCacheEntry>" in source
    assert "TimeSpan.FromMinutes(5)" in source
    assert "ResolveAddressesAsync(host" in source
    assert "cached.ExpiresAt > now" in source


def test_windows_telemetry_keeps_lightweight_borderless_hierarchy():
    source = (ROOT / "bluevpn-windows/App.xaml").read_text()
    style = source[source.index('x:Key="BlueVpnMetricCardStyle"'):]
    assert 'Property="Background" Value="Transparent"' in style
    assert 'Property="BorderThickness" Value="0"' in style
    assert 'Property="Padding" Value="7,6"' in style
    assert "DropShadowEffect" not in style
