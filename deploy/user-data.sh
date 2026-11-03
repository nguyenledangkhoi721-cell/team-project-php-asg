#!/bin/bash
# Copy into EC2 Launch Template after replacing the three settings below.
set -euo pipefail
export DEBIAN_FRONTEND=noninteractive
REGION='ap-southeast-1'
REPO_URL='https://github.com/REPLACE_OWNER/team-project-php-asg.git'
COMMIT_SHA='REPLACE_WITH_FULL_40_CHARACTER_COMMIT_SHA'
PARAMETER='/lab/team-project/database'
APP='/var/www/team-project'
[[ "$COMMIT_SHA" =~ ^[0-9a-f]{40}$ ]] || { echo 'Set an exact Git commit SHA'; exit 1; }
apt-get update
apt-get install -y apache2 php libapache2-mod-php php-mysql mysql-client git curl ca-certificates python3-boto3 stress-ng
systemctl stop apache2
git clone "$REPO_URL" "$APP"
git -C "$APP" checkout --detach "$COMMIT_SHA"
mkdir -p "$APP/runtime"
curl --fail --silent --show-error --retry 4 \
 https://truststore.pki.rds.amazonaws.com/global/global-bundle.pem \
 -o /etc/ssl/certs/aws-rds-global-bundle.pem
chmod 644 /etc/ssl/certs/aws-rds-global-bundle.pem
python3 "$APP/deploy/fetch_config.py" --region "$REGION" \
 --parameter "$PARAMETER" --output "$APP/config/database.json"
chown root:www-data "$APP/config/database.json"
chmod 640 "$APP/config/database.json"
cp "$APP/deploy/team-project.conf" /etc/apache2/sites-available/team-project.conf
a2dissite 000-default.conf
a2ensite team-project.conf
apache2ctl configtest
curl --fail --silent --show-error --retry 4 \
 https://amazoncloudwatch-agent.s3.amazonaws.com/ubuntu/amd64/latest/amazon-cloudwatch-agent.deb \
 -o /tmp/amazon-cloudwatch-agent.deb
dpkg -i /tmp/amazon-cloudwatch-agent.deb
touch /var/log/apache2/team-project-access.log /var/log/apache2/team-project-error.log
/opt/aws/amazon-cloudwatch-agent/bin/amazon-cloudwatch-agent-ctl \
 -a fetch-config -m ec2 -s -c "file:$APP/monitoring/cloudwatch-agent.json"
systemctl enable apache2
touch "$APP/runtime/ready"
systemctl restart apache2
curl --fail --silent http://127.0.0.1/health.php
echo 'TEAM_APP provisioning completed'
# No schema import here: creating or replacing a web node must never reset data.
