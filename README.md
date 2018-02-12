# Mutex implementation

## Current status
### General
[![Build Status](https://secure.travis-ci.org/nokitakaze/php-mutex.png?branch=master)](http://travis-ci.org/nokitakaze/php-mutex)
[![Scrutinizer Code Quality](https://scrutinizer-ci.com/g/nokitakaze/php-mutex/badges/quality-score.png?b=master)](https://scrutinizer-ci.com/g/nokitakaze/php-mutex/)
![Code Coverage](https://scrutinizer-ci.com/g/nokitakaze/php-mutex/badges/coverage.png?b=master)
<!-- [![Latest stable version](https://img.shields.io/packagist/v/nokitakaze/mutex.svg?style=flat-square)](https://packagist.org/packages/nokitakaze/mutex) -->

## Usage
At first
```bash
composer require nokitakaze/mutex
```

And then
```php
require_once 'vendor/autoload.php';
$mutex = new FileMutex([
    'name' => 'foobar',
]);
```
