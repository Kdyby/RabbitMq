lint:
	vendor/bin/parallel-lint -e php,phpt --exclude vendor .

phpstan:
	vendor/bin/phpstan analyse -c phpstan.neon --no-progress --no-ansi --memory-limit=-1

run-tests:
	vendor/bin/tester -s -c ./tests/php.ini-unix ./tests/KdybyTests/

run-tests-coverage:
	vendor/bin/tester --coverage ./coverage.xml --coverage-src ./src -s -p phpdbg -c ./tests/php.ini-unix ./tests/KdybyTests/

coveralls:
	vendor/bin/php-coveralls --verbose --config tests/.coveralls.yml
