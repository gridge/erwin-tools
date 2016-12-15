#!/bin/sh
#Trigger update of DB from dropbox
/home/pi/code/Dropbox-Uploader/dropbox_uploader.sh -f /home/pi/.dropbox_uploader download 'M&G/FamilyBudget/FamilyBudget.gnucash' /home/pi/data/FamilyBudget/FamilyBudget.gnucash > /dev/null
#touch /home/pi/code/erwin-tools/familybudget-report/alive.me
