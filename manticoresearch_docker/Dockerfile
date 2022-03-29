FROM ubuntu:focal
ENV DEBIAN_FRONTEND noninteractive

RUN apt-get update && apt-get install -y \
    libmysqlclient-dev \
    libexpat-dev \
    libpq-dev \
    unixodbc-dev \
    flex \
    bison \
    git \
    build-essential \
    libjemalloc-dev \
    libssl-dev \
    wget \
    ca-certificates \
    ninja-build \
    lsb-release \
    software-properties-common \
&& rm -rf /var/lib/apt/lists/*

ENV DISTR focal
ENV CXXFLAGS "-Wno-error=deprecated-copy"

ARG boostminorver=75
RUN wget https://boostorg.jfrog.io/artifactory/main/release/1.${boostminorver}.0/source/boost_1_${boostminorver}_0.tar.gz \
    && tar -zxf boost_1_${boostminorver}_0.tar.gz && rm boost_1_${boostminorver}_0.tar.gz \
    && cd boost_1_${boostminorver}_0 \
    && ./bootstrap.sh \
    && ./b2 install  --with-context --with-system \
    && cd .. && rm -rf boost_1_${boostminorver}_0

ARG cmakever="3.20.2"
ARG cmakesuff="${cmakever}-linux"
RUN cd / \
    && wget https://github.com/Kitware/CMake/releases/download/v${cmakever}/cmake-${cmakesuff}-x86_64.tar.gz \
    && tar -zxf cmake-${cmakesuff}-x86_64.tar.gz \
    && rm cmake-${cmakesuff}-x86_64.tar.gz
ENV PATH $PATH:/cmake-${cmakesuff}-x86_64/bin

# installs gcc 10.3
#RUN apt install -y gcc-10 g++-10 && rm /usr/bin/gcc /usr/bin/g++ && ln -s /usr/bin/gcc-10 /usr/bin/gcc && ln -s /usr/bin/g++-10 /usr/bin/g++

# installs gcc 5.3
#RUN echo "deb http://dk.archive.ubuntu.com/ubuntu/ xenial universe" >> /etc/apt/sources.list && echo "deb http://dk.archive.ubuntu.com/ubuntu/ xenial main" >> /etc/apt/sources.list && apt update && apt install -y g++-5 gcc-5 && rm /usr/bin/gcc /usr/bin/g++ && ln -s /usr/bin/gcc-5 /usr/bin/gcc && ln -s /usr/bin/g++-5 /usr/bin/g++

# installs gcc 5.4
#RUN echo "deb http://dk.archive.ubuntu.com/ubuntu/ xenial universe" >> /etc/apt/sources.list && echo "deb http://dk.archive.ubuntu.com/ubuntu/ xenial main" >> /etc/apt/sources.list && echo "deb http://dk.archive.ubuntu.com/ubuntu/ xenial-updates universe" >> /etc/apt/sources.list && echo "deb http://dk.archive.ubuntu.com/ubuntu/ xenial-updates main" >> /etc/apt/sources.list && apt update && apt install -y g++-5 gcc-5 && rm /usr/bin/gcc /usr/bin/g++ && ln -s /usr/bin/gcc-5 /usr/bin/gcc && ln -s /usr/bin/g++-5 /usr/bin/g++

RUN wget https://apt.llvm.org/llvm.sh
RUN chmod +x llvm.sh
RUN ./llvm.sh 13
ENV CC=clang-13
ENV CXX=clang++-13

ARG columnar_commit=unknown
ARG daemon_commit=unknown
ARG columnar_token=unknown
ARG daemon_token=unknown
RUN cd / && git clone https://perf:$columnar_token@gitlab.com/manticoresearch/columnar.git columnar && cd columnar && git checkout $columnar_commit
RUN cd / && git clone https://perf:$daemon_token@gitlab.com/manticoresearch/dev.git manticore_gitlab && cd manticore_gitlab && git checkout $daemon_commit && echo "SOURCE_DIR /columnar/" > local_columnar_src.txt
RUN cd /manticore_gitlab/ && git submodule update --init --recursive
RUN cd /columnar && echo "SOURCE_DIR /manticore_gitlab/" > local_manticore_src.txt && mkdir build && cd build && cmake -DCMAKE_BUILD_TYPE=Release .. || true
RUN cd /columnar/build && cmake -DCMAKE_BUILD_TYPE=Release ..
RUN cd /columnar/build && make -j32 && mkdir -p /usr/share/manticore/modules/ && cp columnar/lib_manticore_columnar.so /usr/share/manticore/modules/lib_manticore_columnar.so && cp ./_deps/manticore-build/src/searchd /usr/bin/ && cp ./_deps/manticore-build/src/indexer /usr/bin/
RUN mkdir /var/lib/manticore && mkdir /var/log/manticore && mkdir /var/run/manticore && mkdir /etc/manticoresearch
WORKDIR /
EXPOSE 9306
ENV LANG C.UTF-8
ENV LC_ALL C.UTF-8
CMD ["searchd", "--nodetach", "-c", "/etc/manticoresearch/manticore.conf"]

