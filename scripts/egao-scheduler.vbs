' Menjalankan "php artisan schedule:run" tanpa jendela konsol (dipanggil Windows Task Scheduler tiap menit).
' Pemakaian: wscript.exe "C:\path\egao\scripts\egao-scheduler.vbs" "C:\path\php.exe"
Option Explicit

Dim fso, shell, root, php
Set fso = CreateObject("Scripting.FileSystemObject")
Set shell = CreateObject("WScript.Shell")

root = fso.GetParentFolderName(fso.GetParentFolderName(WScript.ScriptFullName))
If WScript.Arguments.Count > 0 Then
    php = WScript.Arguments(0)
Else
    php = "php"
End If

shell.CurrentDirectory = root
' 0 = jendela tersembunyi, True = tunggu selesai (mencegah tumpang tindih antarputaran).
shell.Run """" & php & """ """ & root & "\artisan"" schedule:run", 0, True
