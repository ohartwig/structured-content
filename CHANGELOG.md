# Changelog

This package continues `moselwal/structured-content` (last release 2.13.4) as
`koh/structured-content` with a fresh history. Releases from 2.14.0 on are listed here.

## [2.14.7](https://git.ole-hartwig.eu/ai-ready-platform/platform/structured-content/compare/v2.14.6...v2.14.7) (2026-11-08)

### :zap: Refactor

* **glossary:** move the batch lookup into its own optional interface ([b393e93](https://git.ole-hartwig.eu/ai-ready-platform/platform/structured-content/commit/b393e93c6194b1700aef046ba4ea0113a5ede67b))

### :fast_forward: Performance

* **glossary:** look up linked terms in one query per page ([e6f0bd1](https://git.ole-hartwig.eu/ai-ready-platform/platform/structured-content/commit/e6f0bd1a55779737ad59a75dc8270671cfe12275))

### :white_check_mark: Tests

* **infection:** run mutation testing on the Unit suite only ([3403e06](https://git.ole-hartwig.eu/ai-ready-platform/platform/structured-content/commit/3403e06c5af70fd77b58e13603e8f573ef158dac))

### :repeat: Chores

* **repo-templates:** sync ([93012a3](https://git.ole-hartwig.eu/ai-ready-platform/platform/structured-content/commit/93012a379281928a73f581b322ec75e0ee682de8))
* **repo-templates:** sync ([e375993](https://git.ole-hartwig.eu/ai-ready-platform/platform/structured-content/commit/e3759937f5867b3bdcafb04d642e1363b3799eb3))
* **deps:** update dependency registry.ole-hartwig.eu/devops/images/franken-php/ci to v3.2.150-8.5 ([2aa516a](https://git.ole-hartwig.eu/ai-ready-platform/platform/structured-content/commit/2aa516a4b39568bdf39f79f5b6946a21b111c781))
* **deps:** update dependency registry.ole-hartwig.eu/devops/images/franken-php/ci to v3.2.149-8.5 ([fd83874](https://git.ole-hartwig.eu/ai-ready-platform/platform/structured-content/commit/fd838741c5bdc2852ee6f4038d6a1c2c8e3c4e75))
* **deps:** update dependency registry.ole-hartwig.eu/devops/images/franken-php/ci to v3.2.148-8.5 ([94664aa](https://git.ole-hartwig.eu/ai-ready-platform/platform/structured-content/commit/94664aaa84393c243d85277eefa7d27fa85121e0))
* **repo-templates:** sync ([5af14ae](https://git.ole-hartwig.eu/ai-ready-platform/platform/structured-content/commit/5af14ae6286ea13aa35de1b9a26fe38448d56976))
* **repo-templates:** sync ([1ceb425](https://git.ole-hartwig.eu/ai-ready-platform/platform/structured-content/commit/1ceb425a3bdabca9bbb82dcf169bee65850b3c39))
* **repo-templates:** sync ([1990764](https://git.ole-hartwig.eu/ai-ready-platform/platform/structured-content/commit/1990764ec54fc037f19a44664bd21417b4be8275))
* **deps:** update dependency registry.ole-hartwig.eu/devops/images/franken-php/ci to v3.2.144-8.5 ([f52a01d](https://git.ole-hartwig.eu/ai-ready-platform/platform/structured-content/commit/f52a01dafc7ca13f684b8fb911bcdd7c53abaef4))
* **deps:** update dependency registry.ole-hartwig.eu/devops/images/franken-php/ci to v3.2.143-8.5 ([d1f1515](https://git.ole-hartwig.eu/ai-ready-platform/platform/structured-content/commit/d1f15158995c82e55d1aab008f38adc55817e241))
* **repo-templates:** sync ([975639d](https://git.ole-hartwig.eu/ai-ready-platform/platform/structured-content/commit/975639d8807f4255abcd186473932d9bcb1cb723))
* **deps:** update dependency registry.ole-hartwig.eu/devops/images/franken-php/ci to v3.2.142-8.5 ([42e51bc](https://git.ole-hartwig.eu/ai-ready-platform/platform/structured-content/commit/42e51bc8e07af4a4522aadbf65d474d2a1217ec2))
* **deps:** update dependency registry.ole-hartwig.eu/devops/images/franken-php/ci to v3.2.140-8.5 ([9895eea](https://git.ole-hartwig.eu/ai-ready-platform/platform/structured-content/commit/9895eeacfa70a02b44d2326ff9b0b900a7ee0344))
* **deps:** update dependency registry.ole-hartwig.eu/devops/images/franken-php/ci to v3.2.139-8.5 ([a8c7900](https://git.ole-hartwig.eu/ai-ready-platform/platform/structured-content/commit/a8c79007c10217e671a9a8482f922c087a826b9b))
* **deps:** update dependency registry.ole-hartwig.eu/devops/images/franken-php/ci to v3.2.138-8.5 ([59fc7ad](https://git.ole-hartwig.eu/ai-ready-platform/platform/structured-content/commit/59fc7ada3d41e8b2d48a70abe8fecac835b451ca))
* **deps:** update dependency registry.ole-hartwig.eu/devops/images/franken-php/ci to v3.2.137-8.5 ([3d05b43](https://git.ole-hartwig.eu/ai-ready-platform/platform/structured-content/commit/3d05b4310f5c1f35fcadb788b67269154a44dcd1))
* **repo-templates:** sync ([cf28075](https://git.ole-hartwig.eu/ai-ready-platform/platform/structured-content/commit/cf28075f92f1b8247b05a5302c90f6f041fc69ed))
* **repo-templates:** sync ([fa66888](https://git.ole-hartwig.eu/ai-ready-platform/platform/structured-content/commit/fa66888e9599b1c96ccb944a4817be9fafb86ce5))
* **repo-templates:** sync ([daafe3a](https://git.ole-hartwig.eu/ai-ready-platform/platform/structured-content/commit/daafe3ade621f30e5ddd32ae0f164bfee43edfc9))
* **repo-templates:** sync ([9180234](https://git.ole-hartwig.eu/ai-ready-platform/platform/structured-content/commit/9180234f9038185597b9d9daae2b84b82b3456c7))

## [2.14.6](https://git.ole-hartwig.eu/ai-ready-platform/platform/structured-content/compare/v2.14.5...v2.14.6) (2026-10-29)

### :bug: Fixes

* **cache:** cache JSON-LD only for plain public requests ([53c45fb](https://git.ole-hartwig.eu/ai-ready-platform/platform/structured-content/commit/53c45fb5908158009b33c04d689ed87e64c3289a))

### :repeat: Chores

* **repo-templates:** sync ([4094f9e](https://git.ole-hartwig.eu/ai-ready-platform/platform/structured-content/commit/4094f9e20bded3f921a19fc9d0b3c22087fbd427))

## [2.14.5](https://git.ole-hartwig.eu/ai-ready-platform/platform/structured-content/compare/v2.14.4...v2.14.5) (2026-10-18)

### :bug: Fixes

* **glossary:** resolve the glossary path in the page's language ([346069c](https://git.ole-hartwig.eu/ai-ready-platform/platform/structured-content/commit/346069c8ca14b6c10534de4927dc3c2f292ece5f))

### :white_check_mark: Tests

* **podcast:** give the episode double the shape the feed writes ([6d11bea](https://git.ole-hartwig.eu/ai-ready-platform/platform/structured-content/commit/6d11bea967af0e4df1af8063e5249777f741ac6c))

## [2.14.4](https://git.ole-hartwig.eu/ai-ready-platform/platform/structured-content/compare/v2.14.3...v2.14.4) (2026-10-18)

### :bug: Fixes

* admit PHP 8.3 and test the floor on it ([c91264f](https://git.ole-hartwig.eu/ai-ready-platform/platform/structured-content/commit/c91264fac7ac064b7673c910a15445d9d5804518))
* **docs:** write the README in English throughout ([7a46708](https://git.ole-hartwig.eu/ai-ready-platform/platform/structured-content/commit/7a46708bf4cb5105dd4613aad8b32711d1755794))

### :white_check_mark: Tests

* cover the dates the floor change touched ([b8d60a1](https://git.ole-hartwig.eu/ai-ready-platform/platform/structured-content/commit/b8d60a14e933966a98411ebd0e35f9b60491d622))

## [2.14.3](https://git.ole-hartwig.eu/ai-ready-platform/platform/structured-content/compare/v2.14.2...v2.14.3) (2026-10-16)

### :repeat: Chores

* **repo-templates:** sync ([306f908](https://git.ole-hartwig.eu/ai-ready-platform/platform/structured-content/commit/306f908c0cc75d259212c0984a04b37303aedf45))

## [2.14.2](https://git.ole-hartwig.eu/ai-ready-platform/platform/structured-content/compare/v2.14.1...v2.14.2) (2026-10-14)

### :bug: Fixes

* **cache:** keep the JSON-LD cache in the database and tag it with the page ([81c5176](https://git.ole-hartwig.eu/ai-ready-platform/platform/structured-content/commit/81c517661e5170ec78c06d53734de7e3df45f356))

## [2.14.1](https://git.ole-hartwig.eu/ai-ready-platform/platform/structured-content/compare/v2.14.0...v2.14.1) (2026-10-14)

### :repeat: Chores

* **repo-templates:** sync ([8383395](https://git.ole-hartwig.eu/ai-ready-platform/platform/structured-content/commit/8383395779c0dcd11af9530760ddf55426a088ac))

## [2.14.0] (2026-10-13)

### :boom: BREAKING CHANGES

* continue moselwal/structured-content as koh/structured-content

### :sparkles: Features

* continue moselwal/structured-content as koh/structured-content ([e6ae8fd](https://git.ole-hartwig.eu/ai-ready-platform/platform/structured-content/commit/e6ae8fd6fde097a17c90fbf60358920f74268762))

