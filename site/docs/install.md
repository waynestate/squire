---
title: "Install | Squire docs"
description: "Install Squire as a dev dependency, write your app's deploy scripts, and verify them with squire doctor."
url: https://squire.apps.wayne.edu/docs/install.html
llms: https://squire.apps.wayne.edu/llms.txt
generator: Squire agent-ready
---

# Install | Squire docs

## Requirements[#](https://squire.apps.wayne.edu/docs/install.html#requirements)

PHP `^8.3`, tested on 8.3, 8.4 and 8.5. The package runs on your machine or in CI, not on Citadel; what lands on Citadel is the scripts it wrote.

## Install[#](https://squire.apps.wayne.edu/docs/install.html#install)

```
composer require waynestate/squire --dev
vendor/bin/squire init
vendor/bin/squire doctor
```

## Verify[#](https://squire.apps.wayne.edu/docs/install.html#verify)

`vendor/bin/squire doctor` checks the app against the best practices a machine can decide. Exit codes: `0` pass, `1` findings (`init --check`: drift; `update`: an edited file still differs from the template; `doctor`: a failed rule), `2` the tool itself could not run.

Next: the [command reference](https://squire.apps.wayne.edu/docs/reference/commands.html).
