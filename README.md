# Traceguide Instrumentation Library

## Install with Composer

```bash
composer require traceguide/api-php
```

## Instrumentation Example

```php
<?php

require __DIR__ . '/vendor/autoload.php';

Traceguide::initialize('examples/trivial_process', '{{access_token_goes_here}}');
Traceguide::infof("Initialized! Unix time = %d", time());

$span = Traceguide::startSpan();
$span->setOperation("trivial/loop");
for ($i = 0; $i < 10; $i++) {
    $span->infof("Loop iteration %d", $i);
    echo "The current unix time is " . time() . "\n";
    sleep(1);
}
$span->finish();
```

## License

[The MIT License](LICENSE).

Copyright (c) 2015, Resonance Labs.




