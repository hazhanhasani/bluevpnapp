# BlueVPN 4.12.5 — Build and Test

- Release validator: PASS
- Python regression suite: 448/448 PASS
- WordPress authoritative PHP release validation: 25/25 PASS
- PHP syntax lint across Manager + Site: 40 files PASS
- GitHub workflow YAML parse: 3/3 PASS
- Android runtime basis remains v2rayNG 2.2.6 + AndroidLibXrayLite v26.7.5.
- Free Story video path now downloads short video to local cache, renders with TextureView + MediaPlayer, waits for MEDIA_INFO_VIDEO_RENDERING_START, and fails open if no real video frame appears.
- WordPress Story uploader now accepts new video uploads as MP4 and explicitly requires/recommends H.264/AVC + AAC for Android compatibility.
- Bundled BlueVPN Site theme: 1.3.14.

Full Gradle APK compilation and physical-device playback were not executed in this environment; GitHub Actions/device validation remains the final runtime gate.
