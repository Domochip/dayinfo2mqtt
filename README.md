# How does it work

This image publishes once a day some usefull information about the current day:  
- Basic information
- Moon
- School Holidays
- Public Holidays
- Season

![Diagram](https://raw.githubusercontent.com/Domochip/dayinfo2mqtt/master/diagram.svg)

# How-to
## Install
For Docker, run it by executing the following commmand:

```bash
docker run \
    -d \
    --name dayinfo2mqtt \
    --restart=always \
    -e TZ="Europe/Paris" \
    -e PUBLISHHOUR=0 \
    -e FEATURESLIST="base,moon,schoolholidays,publicholidays,season" \
    -e COUNTRY="fr" \
    -e DEPARTMENT="75" \
    -e HOST="192.168.1.x" \
    -e PORT=1883 \
    -e PREFIX="dayinfo2mqtt" \
    -e CLIENTID="dayinfo2mqttclid" \
    -e USER="usr" \
    -e PASSWORD="pass" \
    domochip/dayinfo2mqtt
```
For Docker-Compose, use the following yaml:

```yaml
version: '3'
services:
  dayinfo2mqtt:
    container_name: dayinfo2mqtt
    image: domochip/dayinfo2mqtt
    environment:
    - TZ=Europe/Paris
    - PUBLISHHOUR=0
    - FEATURESLIST=base,moon,schoolholidays,publicholidays,season
    - COUNTRY=fr
    - DEPARTMENT=75
    - HOST=192.168.1.x
    - PORT=1883
    - PREFIX=dayinfo2mqtt
    - CLIENTID=dayinfo2mqttclid
    - USER=mqtt_username
    - PASSWORD=mqtt_password
    restart: always
```

### Configure

#### Environment variables
* `TZ`: **Optional**, (Linux TimeZone) Timezone used to schedule publish
* `PUBLISHHOUR`: **Optional**, (Integer: 0 to 23) hour of publish everyday
* `FEATURESLIST`: **Optional**, (comma separated list) List of information published containing one or more features within (base,moon,schoolholidays,publicholidays,season)
* `COUNTRY`: **Optional**,('fr' or 'ch' or 'be') country used to define local information
* `DEPARTMENT`: **Optional**,(Int: French Department) department used to define local information
* `HOST`: IP address or hostname of your MQTT broker
* `PORT`: **Optional**, port of your MQTT broker
* `PREFIX`: **Optional**, prefix used in topics for subscribe/publish
* `CLIENTID`: **Optional**, MQTT client id to use
* `USER`: **Optional**, MQTT username
* `PASSWORD`: **Optional**, MQTT password

## Published Informations

### Technical information

* `{prefix}/connected`: 0 or 1, Indicates connection status of the container
* `{prefix}/executionTime`: DateTime, execution time of the publication

### Base

Basic information:  
* `{prefix}/base/weekend`: 0 or 1, Indicates if today is Saturday or Sunday
* `{prefix}/base/weekday`: 0 (for Sunday) through 6 (for Saturday), Numeric representation of the day of the week
* `{prefix}/base/yearday`: 0 through 365, The day of the year (starting from 0)

### Moon

Moon information:  
* `{prefix}/moon/phase`: 0 to 1, Phase of the moon
* `{prefix}/moon/age`: days, Age of moon
* `{prefix}/moon/illumination`: 0 to 1, Illuminated fraction
* `{prefix}/moon/distance`: Km, Distance
* `{prefix}/moon/name`: ('New Moon','Waxing Crescent','First Quarter','Waxing Gibbous','Full Moon','Waning Gibbous','Third Quarter','Waning Crescent','New Moon'), Name of the moon phase

### School Holidays

School holidays are stored in some ics files and included into this image.
Supported countries are:  
- fr: France
- ch: Switzerland
- be: Belgium

School holidays information:  
* `{prefix}/schoolholidays/today`: 0 or 1, Indicates if today is a school holiday
* `{prefix}/schoolholidays/todaylabel`: '' or '{Name}', Name of the school holiday if today is one
* `{prefix}/schoolholidays/nextbegin`: day, Number of days before next start of school holiday period
* `{prefix}/schoolholidays/nextend`: day, Number of days before next end of school holiday period
* `{prefix}/schoolholidays/nextlabel`: '' or '{Name}', Name of the next school holiday period

### Public Holidays

School holidays information:  
* `{prefix}/publicholidays/today`: 0 or 1, Indicates if today is a school holiday
* `{prefix}/publicholidays/todaylabel`: '' or '{Name}', Name of the public holiday if today is one
* `{prefix}/publicholidays/nextin`: day, Number of days before next public holiday
* `{prefix}/publicholidays/nextlabel`: '' or '{Name}', Name of the next public holiday

### Season

Season information:  
* `{prefix}/season/current`: ('Summer','Fall','Winter','Spring'), Name of the current season
* `{prefix}/season/next`: ('Summer','Fall','Winter','Spring'), Name of the next season
* `{prefix}/season/nextin`: day, Number of days before next season

# Troubleshoot
## Logs
You need to have a look at logs using :  
`docker logs dayinfo2mqtt`

# Updating
To update to the latest Docker image:
```bash
docker stop dayinfo2mqtt
docker rm dayinfo2mqtt
docker rmi domochip/dayinfo2mqtt
# Now run the container again, Docker will automatically pull the latest image.
```
# Ref/Thanks

I want to thanks a lot **Lunarok** for his Jeedom plugin dayinfo (Infos du jour) which is the original code/idea of this Docker Image :  
* https://github.com/lunarok/jeedom_dayinfo
* https://market.jeedom.com/index.php?v=d&p=market_display&id=1808
