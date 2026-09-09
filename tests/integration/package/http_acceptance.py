#!/usr/bin/env python3
"""Exercise the shipped endpoint with actual Cacti login sessions and CSRF."""

import argparse
from html.parser import HTMLParser
import http.cookiejar
import json
import os
from pathlib import Path
import urllib.error
import urllib.parse
import urllib.request


class Inputs(HTMLParser):
    def __init__(self, html):
        super().__init__()
        self.values = {}
        self.feed(html)

    def handle_starttag(self, tag, attrs):
        attrs = dict(attrs)
        if tag == 'input' and attrs.get('name'):
            self.values[attrs['name']] = attrs.get('value', '')


class Session:
    def __init__(self, base):
        self.base = base.rstrip('/') + '/'
        self.opener = urllib.request.build_opener(
            urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
        self.token = ''

    def request(self, path, values=None):
        data = urllib.parse.urlencode(values).encode() if values is not None else None
        try:
            response = self.opener.open(self.base + path, data=data, timeout=60)
        except urllib.error.HTTPError as error:
            response = error
        with response:
            return response.status, response.read().decode(), response.headers.get_content_type()

    def login(self, username, password):
        status, html, _ = self.request('index.php')
        values = Inputs(html).values
        assert '__csrf_magic' in values, 'Login form has no CSRF token'
        status, html, _ = self.request('index.php', {
            '__csrf_magic': values['__csrf_magic'], 'action': 'login',
            'login_username': username, 'login_password': password})
        assert status == 200 and 'login_password' not in Inputs(html).values, 'Login failed'
        self.token = Inputs(html).values.get('__csrf_magic', '')
        if not self.token:
            # csrf-magic also exposes the token to JavaScript on non-form pages.
            import re
            match = re.search(r'var csrfMagicToken\s*=\s*[\'\"]([^\'\"]+)', html)
            assert match, 'Authenticated page has no CSRF token'
            self.token = match[1]

    def action(self, action, expected_status, expected_code, **values):
        request = {'action': action, '__csrf_magic': self.token, **values}
        status, body, content_type = self.request('plugins/gnmi/ajax_handler.php', request)
        assert content_type == 'application/json', action + ': response is not JSON'
        payload = json.loads(body)
        assert status == expected_status, f'{action}: unexpected HTTP status {status}'
        assert payload.get('code') == expected_code, f'{action}: unexpected response code'
        assert payload.get('success') is (expected_status < 400), action + ': wrong success flag'
        print('PASS:', action, expected_code)
        return payload.get('data', {})


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('url')
    parser.add_argument('fixture', type=Path)
    args = parser.parse_args()
    fixture = json.loads(args.fixture.read_text())
    host = {'host_id': fixture['host_id']}
    device = {**host, 'device_id': fixture['device_id']}
    password = os.environ['CACTI_ADMIN_PASSWORD']
    anonymous = Session(args.url)
    status, body, content_type = anonymous.request('plugins/gnmi/ajax_handler.php')
    assert status == 405 and content_type == 'application/json'
    assert json.loads(body)['code'] == 'method_not_allowed'
    print('PASS: GET method_not_allowed')
    anonymous.action('add_subscription', 403, 'permission_denied', **device)
    admin = Session(args.url)
    admin.login('admin', password)
    admin.action('add_subscription', 403, 'invalid_csrf', __csrf_magic='invalid', **device)
    admin.action('add_subscription', 400, 'invalid_request', subscription_path='', **device)
    subscription = admin.action('add_subscription', 201, 'subscription_created',
        subscription_path='/interfaces/interface/state/counters', instance_identifier='package-http',
        auto_create_datasources=0, **device)['subscription_id']
    sub = {**host, 'subscription_id': subscription}
    admin.action('update_subscription', 200, 'subscription_updated', notes='HTTP acceptance', **sub)
    admin.action('update_subscription', 404, 'target_unavailable',
        subscription_id=subscription, host_id=host['host_id'] + 100000, notes='denied')
    metric = admin.action('add_metric', 201, 'metric_created',
        metric_name='in-octets', rrd_type='COUNTER', enabled=1, **sub)['metric_id']
    target = {**host, 'metric_id': metric}
    admin.action('update_metric', 200, 'metric_updated', enabled=1, **target)
    datasource = admin.action('create_datasource', 201, 'datasource_created', **target)
    assert datasource['local_data_id'] > 0
    admin.action('create_datasource', 200, 'datasource_exists', **target)
    partner = admin.action('add_metric', 201, 'metric_created',
        metric_name='out-octets', rrd_type='COUNTER', enabled=1, **sub)['metric_id']
    admin.action('create_datasource', 201, 'datasource_created', metric_id=partner, **host)
    graph = admin.action('create_graph', 201, 'graph_created', **target)
    assert graph['graph_local_id'] > 0
    admin.action('restart_daemon', 200, 'daemon_restarted', **host)
    denied = Session(args.url)
    denied.login('package-denied', password)
    denied.action('delete_metric', 403, 'permission_denied', **target)
    # Exercise deletion on separate resources; retain the graph-bearing metric
    # so uninstall must actually clean up populated plugin metadata.
    disposable = admin.action('add_subscription', 201, 'subscription_created',
        subscription_path='/interfaces/interface/state', instance_identifier='package-delete',
        auto_create_datasources=0, **device)['subscription_id']
    temporary = admin.action('add_metric', 201, 'metric_created',
        subscription_id=disposable, metric_name='out-octets', rrd_type='COUNTER', **host)['metric_id']
    admin.action('delete_metric', 400, 'confirmation_required', metric_id=temporary, **host)
    admin.action('delete_metric', 200, 'metric_deleted', metric_id=temporary, confirm=1, **host)
    admin.action('delete_subscription', 200, 'subscription_deleted', subscription_id=disposable, confirm=1, **host)
    print('PASS: authenticated packaged management acceptance')


if __name__ == '__main__':
    main()
