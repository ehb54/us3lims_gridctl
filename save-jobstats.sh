#!/bin/sh
#	save-jobstats.sh  --  save uslims3_data job_statistics.xml files

BASEDIR=/srv/www/htdocs/uslims3/uslims3_data
SAVEDIR=/srv/www/htdocs/uslims3/job_stats
STATFILE=job_statistics.xml
if [ ! -d ${SAVEDIR} ]; then
  mkdir ${SAVEDIR}
fi
HOSTNAME=`uname -n`
if [ `echo ${HOSTNAME}|grep -c "novalo"` -ne 0 ]; then
  HOSTNAME="uslims3.aucsolutions.com"
fi
echo "HOSTNAME=${HOSTNAME}"

##JSTIME=`stat ${SAVEDIR} -c %Y`
JSTIME=`date +%s`
##echo "JSTIME=${JSTIME}"
NCFILE=0
NMFILE=0
ONEDAY=86400    # 24-hours worth of seconds

#  Get uslims3_data subdirectories
cd ${BASEDIR}
DATADIRS=`ls -rtF|tail -500|grep '/'`
##DATADIRS=`ls -rtF|tail -200|grep '/'`

for D in $DATADIRS ; do
  # Change to job subdirectory
  JDTIME=`stat ${D} -c %Y`
  AGE=`expr ${JSTIME} - ${JDTIME}`
##echo "D=${D}"
##echo "  JDTIME=${JDTIME}"
##echo "  AGE=${AGE}"

  # Only process directories less than a day old
  if [ ${AGE} -lt ${ONEDAY} ]; then
    JDDATE=`stat ${D} -c %y | cut -d: -f1-2 | sed -e 's/ /-/' -e 's/://g'`
    cd ${BASEDIR}/${D}
##echo "   +++Less than 1 day old+++"

    if [ -f ${STATFILE} ]; then
      # Build job stats if job_statistics.xml exists
      PART1A=`grep cluster= ${STATFILE}|sed -e 's/^.*cluster=\"//'|cut -d'"' -f1`
      PART1A=`echo ${PART1A} | sed -e 's@-@_@'`
      PART1B=`echo ${D} | cut -d'-' -f1`
      PART1=${PART1A}-${PART1B}
      PART2=`grep submittime= ${STATFILE}|sed -e 's/^.*submittime=\"//'|cut -d' ' -f1`
      PART2=`echo ${PART2}|sed -e 's/\-//g'`
      SOUTFILE=${SAVEDIR}/${PART1}-${PART2}-${STATFILE}
##echo "we can build:  ${SOUTFILE}"
      if [ ! -f ${SOUTFILE} ]; then
        echo "cp -p ${STATFILE} ${SOUTFILE}"
        cp -p ${STATFILE} ${SOUTFILE}
        NCFILE=`expr ${NCFILE} + 1`
      fi
    else
      CLUST=`grep shortname ${BASEDIR}/${D}hpcr*xml|cut -d'"' -f4`
      echo "job_statistics.xml in ${D} [ ${JDDATE} ${CLUST} ] does not exist!"
      NMFILE=`expr ${NMFILE} + 1`
    fi
  fi

  # Change back to ./uslims3_data
  cd ${BASEDIR}
done

echo "job_stats+++ Files Created:  ${NCFILE}"
echo "jobxml/stats Files Missing:  ${NMFILE}  (aborted,running jobs?)"
 
