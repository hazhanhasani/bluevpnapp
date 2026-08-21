import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]

class WindowsBlueAiCompileGuard5010Tests(unittest.TestCase):
    def test_reserved_operator_payload_identifier_is_escaped(self):
        src=(ROOT / "bluevpn-windows/Services/WindowsBlueAiService.cs").read_text(encoding="utf-8")
        self.assertIsNone(re.search(r"(?<!@)\boperator\s*=", src), "unescaped C# keyword operator causes CS1041/CS1525")
        self.assertGreaterEqual(src.count("@operator ="), 3)

    def test_json_contract_still_uses_operator_field(self):
        src=(ROOT / "bluevpn-windows/Services/WindowsBlueAiService.cs").read_text(encoding="utf-8")
        self.assertIn("@operator = NetworkContext.Capture().Operator", src)
        self.assertIn("@operator = network.Operator", src)

if __name__ == "__main__":
    unittest.main()
