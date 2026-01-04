FROM debian:bullseye-slim

RUN apt-get update \
    && apt-get install -y --no-install-recommends curl ca-certificates

RUN curl --proto '=https' --tlsv1.2 -sSf https://carthage.software/mago.sh | bash -s -- --install-dir="/bin"

WORKDIR /app