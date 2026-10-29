"""Launch a server-owned argv job detached from the interactive web request."""
import json, os, subprocess, sys
from pathlib import Path
path=Path(sys.argv[1]).resolve(strict=True)
data=json.loads(path.read_text(encoding='utf-8'))
args=data['args']
if not isinstance(args,list) or not args or not all(isinstance(v,str) and '\0'not in v for v in args):raise ValueError('Invalid argv')
env=os.environ.copy();env.update(data.get('env',{}))
flags=0
kwargs={}
if os.name=='nt':flags=subprocess.CREATE_NO_WINDOW|subprocess.DETACHED_PROCESS
else:kwargs['start_new_session']=True
with open(data['stdout_file'],'ab')as out,open(data['stderr_file'],'ab')as err:
    proc=subprocess.Popen(args,cwd=data.get('cwd'),env=env,stdin=subprocess.DEVNULL,stdout=out,stderr=err,close_fds=True,creationflags=flags,**kwargs)
print(json.dumps({'pid':proc.pid}))
