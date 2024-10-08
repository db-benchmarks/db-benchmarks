FROM ubuntu:jammy


RUN apt-get update && apt-get install -y wget && \
    wget https://github.com/zincsearch/zincsearch/releases/download/v0.4.10/zincsearch_0.4.10_linux_x86_64.tar.gz && \
    tar -xvzf zincsearch_0.4.10_linux_x86_64.tar.gz

EXPOSE 4080

ENTRYPOINT /zincsearch