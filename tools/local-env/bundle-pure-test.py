"""把纯逻辑测试和它 require 的类文件拼成一个 PHP 流，方便经 SSH 用 `php -n` 从标准输入执行（本机没有 PHP）。"""
import re, sys
from pathlib import Path
test = Path(sys.argv[1]).resolve()
def inline(match):
    rel = match.group(1)
    src = (test.parent.parent / rel.lstrip('/')).read_text()
    src = re.sub(r'^<\?php', '', src, count=1)
    ns = re.search(r'^namespace ([A-Za-z\\]+);', src, re.M)
    src = re.sub(r'^namespace ([A-Za-z\\]+);', '', src, count=1, flags=re.M)
    return '}\nnamespace ' + ns.group(1) + ' {' + src + '}\nnamespace {'
out = re.sub(r"require dirname\( __DIR__ \) \. '([^']+)';", inline, test.read_text())
sys.stdout.write(out)
