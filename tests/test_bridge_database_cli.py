"""Database discovery and CLI contract tests for the poller bridge."""

import json
import sys
from types import ModuleType, SimpleNamespace
from unittest.mock import Mock

import pytest

from scripts import gnmi_poller_bridge as bridge


def test_parse_cacti_config_reads_required_php_values(tmp_path):
    config = tmp_path / "config.php"
    config.write_text(
        "$database_hostname = 'db';\n"
        '$database_default = "cacti";\n'
        "$database_username='user';\n"
        "$database_password = 'secret';\n"
    )
    assert bridge.parse_cacti_config(str(config)) == {
        "hostname": "db", "database": "cacti", "username": "user", "password": "secret",
    }


def test_parse_cacti_config_rejects_missing_file_and_value(tmp_path):
    with pytest.raises(FileNotFoundError):
        bridge.parse_cacti_config(str(tmp_path / "missing"))
    config = tmp_path / "config.php"
    config.write_text("$database_hostname = 'db';")
    with pytest.raises(ValueError, match="database_database"):
        bridge.parse_cacti_config(str(config))


def test_connect_to_database_uses_parsed_credentials(monkeypatch):
    connect = Mock(return_value=object())
    pymysql = ModuleType("pymysql")
    pymysql.connect = connect
    pymysql.cursors = SimpleNamespace(DictCursor=object())
    monkeypatch.setitem(sys.modules, "pymysql", pymysql)
    monkeypatch.setattr(bridge, "parse_cacti_config", lambda path: {
        "hostname": "db", "database": "cacti", "username": "u", "password": "p",
    })

    connection = bridge.connect_to_database("config.php")

    assert connection is connect.return_value
    assert connect.call_args.kwargs["charset"] == "utf8mb4"
    assert connect.call_args.kwargs["cursorclass"] is pymysql.cursors.DictCursor


def test_connect_to_database_propagates_driver_failure(monkeypatch):
    pymysql = ModuleType("pymysql")
    pymysql.connect = Mock(side_effect=RuntimeError("database down"))
    pymysql.cursors = SimpleNamespace(DictCursor=object())
    monkeypatch.setitem(sys.modules, "pymysql", pymysql)
    monkeypatch.setattr(bridge, "parse_cacti_config", lambda path: {
        "hostname": "db", "database": "cacti", "username": "u", "password": "p",
    })
    with pytest.raises(RuntimeError, match="database down"):
        bridge.connect_to_database()


class FakeCursor:
    def __init__(self, rows, fetchones):
        self.rows = rows
        self.fetchones = iter(fetchones)
        self.queries = []
        self.closed = False

    def execute(self, query, params):
        self.queries.append((query, params))

    def fetchall(self):
        return self.rows

    def fetchone(self):
        return next(self.fetchones)

    def close(self):
        self.closed = True


class FakeDB:
    def __init__(self, cursor):
        self._cursor = cursor
        self.closed = False

    def cursor(self):
        return self._cursor

    def close(self):
        self.closed = True


def test_get_data_source_metrics_uses_database_mapping_and_instance():
    cursor = FakeCursor(
        [{"data_source_name": "in_octets", "metric_name": "in-octets"}],
        [{"instance_identifier": "ettp-40"}],
    )
    result = bridge.get_data_source_metrics(7, FakeDB(cursor))
    assert result == (["in-octets"], "ettp-40", {"in_octets": "in-octets"})
    assert cursor.closed is True


@pytest.mark.parametrize(
    "name_row, expected",
    [
        ({"name": "gNMI instance (eth0)"}, "eth0"),
        ({"name": "gNMI instance without parentheses"}, "default"),
        ({"name": "ordinary source"}, "default"),
        (None, "default"),
    ],
)
def test_get_data_source_metrics_fallbacks(name_row, expected):
    cursor = FakeCursor(
        [{"data_source_name": "metric_in_octets", "metric_name": None}],
        [None, name_row],
    )
    metrics, instance, mapping = bridge.get_data_source_metrics(8, FakeDB(cursor))
    assert metrics == ["in-octets"]
    assert mapping == {"metric_in_octets": "in-octets"}
    assert instance == expected
    assert cursor.closed is True


def test_get_data_source_metrics_rejects_missing_source():
    cursor = FakeCursor([], [])
    with pytest.raises(ValueError, match="No data source"):
        bridge.get_data_source_metrics(99, FakeDB(cursor))
    assert cursor.closed is True


@pytest.mark.parametrize(
    "field, expected",
    [
        ("in_octets", "in-octets"),
        ("_leading", "leading"),
        ("metric_temperature", "temperature"),
        ("port_123", "port-123"),
    ],
)
def test_field_name_to_metric_name_fallbacks(field, expected):
    assert bridge.field_name_to_metric_name(field) == expected


def invoke_main(monkeypatch, tmp_path, **overrides):
    db = overrides.pop("db", FakeDB(FakeCursor([], [])))
    defaults = {
        "connect_to_database": Mock(return_value=db),
        "get_data_source_metrics": Mock(return_value=(["in-octets"], "eth0", {"in_octets": "in-octets"})),
        "read_daemon_storage": Mock(return_value={
            "daemon_status": "connected", "last_update": "now",
            "metric_groups": {"eth0": {"in_octets": 10}},
            "samples_history": {"eth0": [{"epoch": 1, "in-octets": 10}]},
        }),
        "check_staleness": Mock(return_value=False),
        "extract_metrics_from_raw": Mock(return_value={"in_octets": 10}),
        "filter_data_source_metrics": Mock(return_value={"in-octets": 10}),
        "output_cacti_format": Mock(),
        "process_history_output": Mock(return_value=["1:in_octets:10"]),
    }
    defaults.update(overrides)
    for name, value in defaults.items():
        monkeypatch.setattr(bridge, name, value)
    args = [
        "bridge", "--device-id", "1", "--local-data-id", "7",
        "--storage-dir", str(tmp_path), "--config-path", "config.php",
    ]
    monkeypatch.setattr(bridge.sys, "argv", args)
    return db, defaults


def test_main_outputs_current_metrics_and_closes_database(monkeypatch, tmp_path):
    db, calls = invoke_main(monkeypatch, tmp_path)
    with pytest.raises(SystemExit) as exc:
        bridge.main()
    assert exc.value.code == 0
    calls["output_cacti_format"].assert_called_once_with({"in_octets": 10})
    assert db.closed is True


def test_main_outputs_history(monkeypatch, tmp_path, capsys):
    db, _ = invoke_main(monkeypatch, tmp_path)
    bridge.sys.argv.append("--output-history")
    with pytest.raises(SystemExit) as exc:
        bridge.main()
    assert exc.value.code == 0
    assert capsys.readouterr().out.strip() == "1:in_octets:10"
    assert db.closed is True


@pytest.mark.parametrize(
    "override, exit_code",
    [
        ({"connect_to_database": Mock(side_effect=RuntimeError("down"))}, 1),
        ({"get_data_source_metrics": Mock(side_effect=ValueError("missing"))}, 1),
        ({"get_data_source_metrics": Mock(side_effect=RuntimeError("query"))}, 1),
        ({"read_daemon_storage": Mock(return_value=None)}, 1),
        ({"check_staleness": Mock(return_value=True)}, 2),
        ({"read_daemon_storage": Mock(return_value={"daemon_status": "error", "last_update": "now"})}, 2),
        ({"extract_metrics_from_raw": Mock(return_value={})}, 1),
        ({"filter_data_source_metrics": Mock(return_value={})}, 1),
    ],
)
def test_main_failure_contracts(monkeypatch, tmp_path, override, exit_code):
    invoke_main(monkeypatch, tmp_path, **override)
    with pytest.raises(SystemExit) as exc:
        bridge.main()
    assert exc.value.code == exit_code


@pytest.mark.parametrize(
    "data, lines",
    [
        ({"daemon_status": "connected", "last_update": "now"}, []),
        ({"daemon_status": "connected", "last_update": "now", "samples_history": {"eth0": []}}, []),
    ],
)
def test_main_history_requires_samples_and_valid_lines(monkeypatch, tmp_path, data, lines):
    invoke_main(
        monkeypatch,
        tmp_path,
        read_daemon_storage=Mock(return_value=data),
        process_history_output=Mock(return_value=lines),
    )
    bridge.sys.argv.append("--output-history")
    with pytest.raises(SystemExit) as exc:
        bridge.main()
    assert exc.value.code == 1


def test_main_handles_unexpected_and_keyboard_interrupt(monkeypatch, tmp_path):
    invoke_main(monkeypatch, tmp_path, extract_metrics_from_raw=Mock(side_effect=RuntimeError("boom")))
    with pytest.raises(SystemExit) as exc:
        bridge.main()
    assert exc.value.code == 1

    invoke_main(monkeypatch, tmp_path, extract_metrics_from_raw=Mock(side_effect=KeyboardInterrupt()))
    with pytest.raises(SystemExit) as exc:
        bridge.main()
    assert exc.value.code == 1
