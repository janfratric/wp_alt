Read STATUS.md to identify the next ready chunk ({N.N}).
Read CHUNK-{N.N}-PLAN.md, specifically the acceptance test section.
Read tests/chunk-1.1-verify.php as an example of the format and conventions.

Write tests/chunk-{N.N}-verify.php — an automated test script that verifies
all acceptance criteria for the chunk. Follow these conventions:
- Output [PASS], [FAIL], or [SKIP] per test (standardized format)
- Exit code 0 if all pass, 1 if any fail
- Support smoke mode via LITECMS_TEST_SMOKE=1 env var (run only 2-3 core tests)
- Test by instantiating classes and calling methods directly (not HTTP requests)
- Include the autoloader: require_once $rootDir . '/vendor/autoload.php'