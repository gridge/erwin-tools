# erwin-mon
Small set of bash scripts to monirot the performance/load of raspberry Pi
Store statistics into sqlite3 database

The current version running on the system lives in the prod/ directory, to allow development.

## Requirements:
```bash
sudo apt-get install sqlite3 gnuplot ssmtp mutt bc
```

## DB setup
DB name in `config.sh` (default: `/home/pi/data/erwin_mon.db`).
Create a table as:
```
CREATE TABLE 'system_status' ( time INTEGER primary key, temperature REAL, voltage REAL )
```

## Mutt setup
```bash
cat <<EOT >> ~/.muttrc
set from = "erwin.snowball@gmail.com"
set realname = "Erwin Snowball"
set smtp_url = "smtp://erwin.snowball@smtp.gmail.com:587/"
set smtp_pass = "ADD_HERE_PASSWORD"
set ssl_starttls=yes
set ssl_force_tls=yes
```

## Crontab config
Assumes code in `/home/pi/code/erwin-tools/erwin-mon/`.
```
MAILTO="simone.pagangriso@gmail.com"

#erwin_mon (temperature/voltage monitor and alert system)
00 * * * * /home/pi/code/erwin-tools/erwin-mon/prod/cron_store_data.sh > /dev/null
30 0 * * 6 /home/pi/code/erwin-tools/erwin-mon/prod/display_data.sh > /dev/null
```
