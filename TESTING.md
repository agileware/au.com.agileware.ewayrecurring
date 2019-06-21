# Testing

## Automated Tests: Requirements

 * Working CiviCRM installation
 * PHPUnit (https://phpunit.de/)
 * cv (https://github.com/civicrm/cv)
 
## Automated Tests: Setup

A demo civi website, like wp-demo. The demo data need to be installed before running e2e test.  
Civibuild tool can easily set up a demo website, see https://docs.civicrm.org/dev/en/latest/tools/civibuild/  
To set up a demo website in Docker, see https://github.com/michaelmcandrew/civicrm-buildkit-docker

Example for civibuild (change the url to your own):
civibuild create wp5140 \
  --type wp-demo \
  --civi-ver 5.14.0 \
  --url http://localhost:8080

Note: The test currently doesn't check if the demo data present or not.

## Automated Tests: End to end
cd to the extension directory, and
```
phpunit5 --stderr ./tests/phpunit//CRM/EwayRecurring/E2ETest.php
```