## RPI first time setup
Notes for my RPI setup.

### Operative System to SD Card
Raspbian OS from rpi imager:
https://www.raspberrypi.org/software/
```bash
rpi-imager
```

### Minor touches
```bash
echo "alias ll='ls -ltrh" >> ~/.bash_aliases
```

Setup erwin tools (see erwin-mon/README)

Install rkhunter. Download/info: http://rkhunter.sourceforge.net/
Enable in `root` crontab:
```
00 02 * * 5 rkhunter --cronjob --update --quiet
```
More info: https://www.tecmint.com/install-rootkit-hunter-scan-for-rootkits-backdoors-in-linux/

Install gpg
```bash
apt install gpg gpg-conf gpg-agent pinentry-curses

cat <<EOT >> ~/.gnupg/gpg-agent.conf
pinentry-program /usr/bin/pinentry-curses
no-grab
default-cache-ttl 1800
EOT
```
enable `gpg-agent` option, editing ~/.gnupg/gpg.conf
and run agent in `.bashrc`:
```bash
cat << EOT >> ~/.bashrc
# Setup gnupg-agent
NeedToStartAgent=0
GPG_AGENT_PID=`cat "${HOME}/.gpg-agent-info" | grep SSH_AGENT_PID | cut -d'=' -f 2`
if [ -z "${GPG_AGENT_PID}" ]; then
    NeedToStartAgent=1; 
    [ -r .debug_init_bash ] && echo "Debug: Start gpg-agent beacuse no info file: ${HOME}/.gpg-agent-info"
else
    # still check if it is still running
    IsRunning=`ps x | grep ${GPG_AGENT_PID} | grep gpg`
    if [ -z "$IsRunning" ]; then
        [ -r .debug_init_bash ] && echo "Debug: Start gpg-agent beacuse not really running"
        NeedToStartAgent=1;
    fi
fi
if [ ${NeedToStartAgent} -eq 1 ]; then
    [ -r .debug_init_bash ] && echo "Starting new gpg-agent session"
    gpg-agent --daemon --enable-ssh-support --write-env-file "${HOME}/.gpg-agent-info"
else 
    [ -r .debug_init_bash ] && echo "Debug: No need to start gpg-agent"
fi

if [ -f "${HOME}/.gpg-agent-info" ]; then
       . "${HOME}/.gpg-agent-info"
       export GPG_AGENT_INFO
       export SSH_AUTH_SOCK
fi
GPG_TTY=$(tty)
export GPG_TTY
EOT
```

Enable ssh server
```bash
apt install openssh-server
service start ssh
```

Enable unattended upgrades
```bash
sudo apt install unattended-upgrades
```
config on /etc/apt/apt.conf.d/50unattended-upgrades
More info: https://pimylifeup.com/unattended-upgrades-debian-ubuntu/

#### Cronjob backup/synchs
Synch crypted secrets (should move to rclone)
```
#dropbox: update passwords
00 5 * * * /home/pi/code/Dropbox-Uploader/dropbox_uploader.sh -f /home/pi/.dropbox_uploader download personal/console-secrets_gridge.ct /home/pi/data/ > /dev/null
10 5 * * * /home/pi/code/Dropbox-Uploader/dropbox_uploader.sh -f /home/pi/.dropbox_uploader download personal/console-secrets_gridge.ct.bak /home/pi/data/ > /dev/null
```

Double-backup to old disk?
Deep backup to AmazonS3?
 Need to decide if worth it. If so, use rclone.


### Photo Gallery
PiGallery2 photo library
https://github.com/bpatrik/pigallery2

alternatives: https://peterries.net/blog/piwigo-on-raspberry-pi/, https://lycheeorg.github.io/

### Samba configuration
https://pimylifeup.com/raspberry-pi-samba/

### Private VPN
PiVPN (OpenVPN+WireGuard)
https://github.com/pivpn/pivpn

