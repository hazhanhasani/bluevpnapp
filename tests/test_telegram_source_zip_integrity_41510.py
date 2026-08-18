import pathlib, unittest
ROOT = pathlib.Path(__file__).resolve().parents[1]

class TelegramSourceZipIntegrity41601(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.bot = (ROOT/'bluevpn-manager/includes/class-bluevpn-telegram-bot.php').read_text()

    def test_exact_byte_parity_does_not_retry_structural_corruption(self):
        s=self.bot
        block=s[s.index('private static function download_telegram_zip'):s.index('private static function download_telegram_file_streaming')]
        self.assertIn('$transportComplete = $expectedSize > 0 && $size === $expectedSize;', block)
        self.assertIn('if ($transportComplete) break;', block)
        self.assertIn('ZIP قابل Deploy نیست.', block)

    def test_eocd_and_central_directory_are_preflighted(self):
        s=self.bot
        self.assertIn('telegram_zip_has_end_record', s)
        self.assertIn('PK\\x05\\x06', s)
        self.assertIn('Central Directory', s)
        self.assertIn('ZipArchive::CHECKCONS', s)

    def test_error_is_actionable_and_names_er_nozip(self):
        s=self.bot
        self.assertIn('TELEGRAM_SOURCE_ZIP_INVALID', s)
        self.assertIn("19 => 'ER_NOZIP'", s)
        self.assertIn("hash_file('sha256'", s)
        self.assertIn('expected=', s)
        self.assertIn('received=', s)

if __name__ == '__main__':
    unittest.main()
