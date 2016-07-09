#!/bin/sh
# Retrieve data from database and display with gnuplot

#Settings
. /home/pi/code/erwin-tools/erwin-mon/prod/config.sh

#Local settings
tmpFile=/tmp/tmpDataErwinMon.txt
tmpImage=/tmp/tmpImgErwinMon #Postfix will be added below

#Retrieve data for the last period of time
timeNow=`date +%s`
timeStart=$((timeNow-TimeWindow))
sqlite3 $DbName "select time,temperature,voltage from $DbTable where time>$timeStart" | sed 's/|/ /g' > ${tmpFile}

#Create plot
cat <<EOF | gnuplot 1> /dev/null 2>/dev/null
set autoscale
set term png
set output "${tmpImage}_temp.png"
unset log
unset label
set key left
set xtic auto
set ytic auto
set grid
set title "Erwin performance monitor in the last ${TimeWindow} seconds"
set xlabel "Day"
set xdata time
set timefmt "%s"
set format x "%d"
plot "${tmpFile}" using 1:2 title 'Temperature' with lines
replot
set output "${tmpImage}_volt.png"
plot "${tmpFile}" using 1:3 title 'Voltage' with lines
EOF

#E-mail results
dateStr=`date -d @${timeNow}`
date2Str=`date -d @${timeStart}`
echo "Erwin performance monitor from/to:\n $date2Str\n $dateStr" | \
mutt -s '[erwin] STATUS' -a ${tmpImage}_temp.png -a ${tmpImage}_volt.png -- ${emailAddress} < /dev/null

#Update html page
cp ${tmpImage}_temp.png /home/pi/code/erwin-tools/erwin-mon/www/pi_temperature.png
cp ${tmpImage}_volt.png /home/pi/code/erwin-tools/erwin-mon/www/pi_voltage.png

#Clean-up
rm -f ${tmpImage}
rm -f ${tmpFile}
