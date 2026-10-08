"""Fake SMTP server: accepts every message and writes it, unchanged, to
<directory>/<timestamp>-<n>.eml. Nothing is relayed anywhere.

Usage: python sink.py [--host 0.0.0.0] [--port 1025] [--dir /var/mail-sink]
"""
import asyncore
import itertools
import optparse
import os
import smtpd
import sys
import time


class FileSinkServer(smtpd.SMTPServer):

    def __init__(self, localaddr, directory):
        smtpd.SMTPServer.__init__(self, localaddr, None)
        self.directory = directory
        self.counter = itertools.count(1)

    def process_message(self, peer, mailfrom, rcpttos, data):
        name = '%s-%d.eml' % (time.strftime('%Y%m%d%H%M%S'), next(self.counter))
        path = os.path.join(self.directory, name)
        partial = path + '.part'

        # Write under a temporary name and rename, so readers never see a
        # half-written .eml file.
        with open(partial, 'wb') as handle:
            handle.write(data)
            if not data.endswith('\n'):
                handle.write('\n')
        os.rename(partial, path)

        sys.stdout.write('%s from=%s to=%s\n' % (name, mailfrom, ','.join(rcpttos)))
        sys.stdout.flush()


def main():
    parser = optparse.OptionParser()
    parser.add_option('--host', default='0.0.0.0')
    parser.add_option('--port', type='int', default=1025)
    parser.add_option('--dir', default='/var/mail-sink')
    options, _ = parser.parse_args()

    if not os.path.isdir(options.dir):
        os.makedirs(options.dir)

    FileSinkServer((options.host, options.port), options.dir)
    sys.stdout.write('smtp-sink listening on %s:%d, writing to %s\n'
                     % (options.host, options.port, options.dir))
    sys.stdout.flush()
    asyncore.loop()


if __name__ == '__main__':
    main()
