# TYPO3 Basics - testing-framework integration demo project

## Introduction

This repository contains a playground project to work on
composer based functional tests with [phpunit/phpunit](https://github.com/sebastianbergmann/phpunit)
and [typo3/testing-framework](https://github.com/typo3/testing-framework).

> NOTE: Work in progress.


### Commands

**Stop ddev project removing data**

```terminal
ddev stop -ROU
```

**Delete repository project from ddev, so folder can be deleted**

```terminal
ddev stop -ROU \
  && ddev delete -O --yes \
  && git clean -xdf -e .idea/  
```