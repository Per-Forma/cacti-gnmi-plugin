import os
import subprocess
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
SCRIPT = ROOT / "deployment" / "bootstrap_local_docker.sh"


def _write_fake_docker(tmp_path: Path) -> tuple[Path, Path]:
    bin_dir = tmp_path / "bin"
    bin_dir.mkdir()
    log = tmp_path / "docker.log"
    fake = bin_dir / "docker"
    fake.write_text(
        """#!/usr/bin/env bash
set -euo pipefail
printf '%s\\n' "$*" >> "$FAKE_DOCKER_LOG"

if [[ ${1:-} == inspect ]]; then
  echo true
  exit 0
fi

args=("$@")
for ((i=0; i<${#args[@]}; i++)); do
  if [[ ${args[$i]} == sha256sum ]]; then
    echo 'test-requirements-hash  /plugin/scripts/requirements.txt'
    exit 0
  fi
  if [[ ${args[$i]} == /plugin/venv/bin/python3 ]]; then
    [[ ${FAKE_VENV_STATE:-incompatible} == current ]]
    exit
  fi
  if [[ ${args[$i]} == python3 && ${args[$((i+1))]:-} == -c ]]; then
    [[ ${FAKE_SYSTEM_PYTHON:-current} == current ]]
    exit
  fi
  if [[ ${args[$i]} == sh && ${args[$((i+1))]:-} == -c ]]; then
    command_text=${args[$((i+2))]:-}
    if [[ $command_text == *'test -f "$1" && cat "$1"'* ]]; then
      [[ ${FAKE_VENV_STATE:-incompatible} == current ]] && echo test-requirements-hash
      exit
    fi
    if [[ $command_text == *'command -v apt-get'* ]]; then
      exit 0
    fi
  fi
done
exit 0
""",
        encoding="utf-8",
    )
    fake.chmod(0o755)
    return bin_dir, log


def _run(
    tmp_path: Path, state: str, system_python: str = "current"
) -> tuple[subprocess.CompletedProcess[str], str]:
    bin_dir, log = _write_fake_docker(tmp_path)
    env = os.environ.copy()
    env.update(
        PATH=f"{bin_dir}:{env['PATH']}",
        FAKE_DOCKER_LOG=str(log),
        FAKE_VENV_STATE=state,
        FAKE_SYSTEM_PYTHON=system_python,
    )
    result = subprocess.run(
        [str(SCRIPT), "--container", "test-cacti", "--plugin-path", "/plugin"],
        cwd=ROOT,
        env=env,
        text=True,
        capture_output=True,
        check=False,
    )
    return result, log.read_text(encoding="utf-8")


def test_current_container_venv_is_a_noop(tmp_path):
    result, calls = _run(tmp_path, "current")

    assert result.returncode == 0, result.stderr
    assert "already current" in result.stdout
    assert "apt-get install" not in calls
    assert "python3 -m venv" not in calls
    assert "rm -rf" not in calls


def test_incompatible_venv_is_built_and_swapped_inside_container(tmp_path):
    result, calls = _run(tmp_path, "incompatible")

    assert result.returncode == 0, result.stderr
    assert "missing or incompatible" in result.stdout
    assert "apt-get install -y python3-venv python3-dev librrd-dev" in calls
    assert "python3 -m venv /plugin/venv.bootstrap." in calls
    assert "pip install -r /plugin/scripts/requirements.txt" in calls
    assert "rm -rf -- /plugin/venv" in calls
    assert "mv -- /plugin/venv.bootstrap." in calls


def test_rejects_unsafe_plugin_path_before_docker_mutation(tmp_path):
    bin_dir, log = _write_fake_docker(tmp_path)
    env = os.environ.copy()
    env.update(PATH=f"{bin_dir}:{env['PATH']}", FAKE_DOCKER_LOG=str(log))

    result = subprocess.run(
        [str(SCRIPT), "--plugin-path", "/"],
        cwd=ROOT,
        env=env,
        text=True,
        capture_output=True,
        check=False,
    )

    assert result.returncode == 2
    assert "specific absolute container path" in result.stderr
    assert not log.exists()


def test_rebuild_rejects_unsupported_container_python_before_mutation(tmp_path):
    result, calls = _run(tmp_path, "incompatible", system_python="old")

    assert result.returncode == 1
    assert "Python 3.12 or later" in result.stderr
    assert "apt-get install" not in calls
    assert "rm -rf" not in calls
