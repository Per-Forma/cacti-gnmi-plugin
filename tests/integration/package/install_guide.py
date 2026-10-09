"""Extract the exact administrator commands shipped in the installation guide."""

import re
from pathlib import Path
import subprocess


def command_block(document: Path, name: str) -> str:
    pattern = rf"<!-- install-guide:{re.escape(name)} -->\s*```bash\n(.*?)\n```"
    matches = re.findall(pattern, document.read_text(), re.S)
    if len(matches) != 1:
        raise ValueError(f"Expected one installation command block: {name}")
    return matches[0] + "\n"


def require_disposable_container(container: str) -> None:
    label = subprocess.check_output(['docker', 'inspect', '--format',
        '{{index .Config.Labels "com.cacti.gnmi.test"}}', container], text=True).strip()
    if label != 'compatibility':
        raise ValueError('Refusing a container without the compatibility-test label')
