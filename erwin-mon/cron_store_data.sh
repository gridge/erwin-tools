#!/bin/sh
# Store current data to database
# Assumes sqlite database has been created with name/table as below and a table exists as
# CREATE TABLE 'system_status' ( time INTEGER primary key, temperature REAL, voltage REAL )

#Settings
. /home/pi/code/erwin_mon/prod/config.sh

#Retrieve data
myTIME=`/bin/date +%s`
myTIMESTR=`/bin/date -d@${myTIME}`
myTEMP=`/opt/vc/bin/vcgencmd measure_temp | cut -d'=' -f 2 | cut -d\' -f 1`
myVOLT=`/opt/vc/bin/vcgencmd measure_volts | cut -d'=' -f 2 | cut -d'V' -f 1`

echo "$myTIME (${myTIMESTR}): T=${myTEMP}C, V=${myVOLT}V"

#Store to database
sqlite3 ${DbName} "insert into ${DbTable} values($myTIME, $myTEMP, $myVOLT);"
echo "Database update status: $?"

#Now check if we're OK
isWarning=`echo ${myTEMP}'>'${TempWarning} | bc -l`
isAlert=`echo ${myTEMP}'>'${TempAlert} | bc -l`
if [ ${isWarning} -eq 1 ]; then
	echo "WARNING: Temperature detected above threshold (${TempWarning}). Sending email to ${emailAddress}"
	echo "WARNING: Temperature detected above threshold (${myTEMP} > ${TempWarning}). " | mutt -s '[erwin] WARNING' -- ${emailAddress}
fi
if [ ${isAlert} -eq 1 ]; then
	echo "ALERT: Temperature above critical threshold (${TempAlert}). Shutting down!"
	/usr/bin/logger "ALERT: Temperature above critical threshold (${TempAlert}). Shutting down!"
	echo "ALERT: Temperature above critical threshold (${myTEMP} > ${TempAlert}). Shutting down!" | mutt -s '[erwin] ALERT' -- ${emailAddress}
	sleep 5
	/sbin/shutdown -h now
fi
