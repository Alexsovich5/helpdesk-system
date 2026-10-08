# Fake SMTP server for the integration tests and the demo stack: every
# message is written to /var/mail-sink as a .eml file and never relayed.
FROM python:2.7

COPY sink.py /usr/local/bin/sink.py

# The files are shared with the PHP containers through a named volume;
# world-writable so the tests (and make mail-clean) can remove them.
RUN mkdir -p /var/mail-sink && chmod 0777 /var/mail-sink

VOLUME /var/mail-sink
EXPOSE 1025

CMD ["python", "-u", "/usr/local/bin/sink.py", "--port", "1025", "--dir", "/var/mail-sink"]
