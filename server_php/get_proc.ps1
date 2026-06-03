Get-Process | Where-Object { $_.Name -match 'httpd|apache|nginx|php|mysqld|xampp' } | Select-Object Name, Id, Path | Format-Table -AutoSize

Get-NetTCPConnection | Where-Object { $_.LocalPort -in 3306, 3308 } | Select-Object LocalPort, OwningProcess, State | Format-Table -AutoSize
