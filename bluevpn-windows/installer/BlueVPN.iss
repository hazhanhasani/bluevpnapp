#ifndef MyVersion
  #define MyVersion "5.9.2"
#endif
#ifndef MyRid
  #define MyRid "win-x64"
#endif
#ifndef PublishDir
  #error PublishDir must be supplied by the build workflow
#endif

#define MyAppName "BlueVPN"
#define MyAppExeName "BlueVPN.exe"
#define MyAppPublisher "BlueVPN"
#define MyAppURL "https://blluepanel.ir"

[Setup]
AppId={{D07F39E1-5D82-4C40-8F60-9FA61F29A4A2}
AppName={#MyAppName}
AppVersion={#MyVersion}
AppVerName={#MyAppName} {#MyVersion}
AppPublisher={#MyAppPublisher}
AppPublisherURL={#MyAppURL}
AppSupportURL={#MyAppURL}
DefaultDirName={autopf}\BlueVPN
DefaultGroupName=BlueVPN
DisableProgramGroupPage=yes
OutputDir=..\..\dist\installers
OutputBaseFilename=BlueVPN-Setup-{#MyVersion}-{#MyRid}
SetupIconFile=..\bluevpn.ico
UninstallDisplayIcon={app}\BlueVPN.exe
Compression=lzma2/ultra64
SolidCompression=yes
WizardStyle=modern
PrivilegesRequired=admin
CloseApplications=yes
RestartApplications=yes
UsePreviousAppDir=yes

#if MyRid == "win-arm64"
ArchitecturesAllowed=arm64
ArchitecturesInstallIn64BitMode=arm64
#else
ArchitecturesAllowed=x64compatible
ArchitecturesInstallIn64BitMode=x64compatible
#endif

[Languages]
Name: "english"; MessagesFile: "compiler:Default.isl"

[Tasks]
Name: "desktopicon"; Description: "Create a desktop shortcut"; GroupDescription: "Shortcuts:"; Flags: checkedonce

[Files]
Source: "{#PublishDir}\*"; DestDir: "{app}"; Flags: ignoreversion recursesubdirs createallsubdirs

[Icons]
Name: "{autoprograms}\BlueVPN"; Filename: "{app}\{#MyAppExeName}"
Name: "{autodesktop}\BlueVPN"; Filename: "{app}\{#MyAppExeName}"; Tasks: desktopicon

[Run]
Filename: "{app}\{#MyAppExeName}"; Description: "Launch BlueVPN"; Flags: nowait postinstall skipifsilent shellexec

[UninstallRun]
Filename: "taskkill"; Parameters: "/F /IM BlueVPN.exe"; Flags: runhidden; RunOnceId: "StopBlueVPN"
