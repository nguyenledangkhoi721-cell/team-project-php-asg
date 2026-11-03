#!/usr/bin/env python3
"""Called as root during provisioning. Never prints the secret."""
import argparse,json,os,pathlib,tempfile
import boto3
p=argparse.ArgumentParser()
p.add_argument('--region',required=True)
p.add_argument('--parameter',required=True)
p.add_argument('--output',required=True)
a=p.parse_args()
value=boto3.client('ssm',region_name=a.region).get_parameter(Name=a.parameter,WithDecryption=True)['Parameter']['Value']
cfg=json.loads(value)
for k in ['host','database','username','password','ssl_ca']:
    if not isinstance(cfg.get(k),str) or not cfg[k]: raise ValueError('Invalid parameter schema')
if cfg['host'] in ['localhost','127.0.0.1']: raise ValueError('Expected a shared RDS endpoint')
target=pathlib.Path(a.output)
fd,tmp=tempfile.mkstemp(dir=target.parent,prefix='.database-')
try:
    with os.fdopen(fd,'w',encoding='utf-8') as f: json.dump(cfg,f)
    os.chmod(tmp,0o600)
    os.replace(tmp,target)
finally:
    if os.path.exists(tmp): os.unlink(tmp)
