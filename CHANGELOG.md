# Changelog

This package continues `moselwal/structured-content` (last release 2.13.4) as
`koh/structured-content` with a fresh history. Releases from 2.14.0 on are listed here.

## [2.15.2](https://git.ole-hartwig.eu/ai-ready-platform/platform/structured-content/compare/v2.15.1...v2.15.2) (2026-11-27)

### :bug: Fixes

* **schema:** date pages by the date they are presented with ([9de3ecb](https://git.ole-hartwig.eu/ai-ready-platform/platform/structured-content/commit/9de3ecb8eeb46ad6e3d49da939045b2aaf624819))
* **schema:** translated, illustrated author pages with one page entity ([761625d](https://git.ole-hartwig.eu/ai-ready-platform/platform/structured-content/commit/761625d2869b620a8cf13230c56ebb2fe954356e))

### :memo: Documentation

* single blank line before the page dates section ([4422fa3](https://git.ole-hartwig.eu/ai-ready-platform/platform/structured-content/commit/4422fa3bc63eb06aaf2789775b501c704ce3a40a))

### :white_check_mark: Tests

* **schema:** cover the author page fixes against mutation testing ([b18c01c](https://git.ole-hartwig.eu/ai-ready-platform/platform/structured-content/commit/b18c01c8b1903559a9208637c12b88aa35aa2d37))

## [2.15.1](https://git.ole-hartwig.eu/ai-ready-platform/platform/structured-content/compare/v2.15.0...v2.15.1) (2026-11-25)

### :sparkles: Features

* **schema:** founder person on sites without an author page, books as Book ([fcc51c3](https://git.ole-hartwig.eu/ai-ready-platform/platform/structured-content/commit/fcc51c3235ceb7c37d14b8c747b8adb6ef589fd8))

### :bug: Fixes

* **schema:** neutral names in the book showcase example and its test ([393b8c6](https://git.ole-hartwig.eu/ai-ready-platform/platform/structured-content/commit/393b8c6e7ae1f5d5224073e5263c51aeca990f43))
* **schema:** plain articleBody with spaces between list items ([d01f9b0](https://git.ole-hartwig.eu/ai-ready-platform/platform/structured-content/commit/d01f9b06ba98fa907e9cf7cc934c0a0dda7c5dee))
* **llms:** feature flagged pages that are hidden only from the menu ([351075c](https://git.ole-hartwig.eu/ai-ready-platform/platform/structured-content/commit/351075ccc61f008c1b8bf421956ebf9d9c50a910))
* **schema:** no reference count as logo, no shortcut pages in llms.txt ([59fccc7](https://git.ole-hartwig.eu/ai-ready-platform/platform/structured-content/commit/59fccc73ef8ee26ceb1ce684bd1bd293eb68fb3e))

## [2.15.0](https://git.ole-hartwig.eu/ai-ready-platform/platform/structured-content/compare/v2.14.12...v2.15.0) (2026-11-25)

### :sparkles: Features

* **schema:** founder person on sites without an author page, books as Book ([fcc51c3](https://git.ole-hartwig.eu/ai-ready-platform/platform/structured-content/commit/fcc51c3235ceb7c37d14b8c747b8adb6ef589fd8))

### :bug: Fixes

* **schema:** plain articleBody with spaces between list items ([d01f9b0](https://git.ole-hartwig.eu/ai-ready-platform/platform/structured-content/commit/d01f9b06ba98fa907e9cf7cc934c0a0dda7c5dee))
* **llms:** feature flagged pages that are hidden only from the menu ([351075c](https://git.ole-hartwig.eu/ai-ready-platform/platform/structured-content/commit/351075ccc61f008c1b8bf421956ebf9d9c50a910))
* **schema:** no reference count as logo, no shortcut pages in llms.txt ([59fccc7](https://git.ole-hartwig.eu/ai-ready-platform/platform/structured-content/commit/59fccc73ef8ee26ceb1ce684bd1bd293eb68fb3e))

## [2.14.12](https://git.ole-hartwig.eu/ai-ready-platform/platform/structured-content/compare/v2.14.11...v2.14.12) (2026-11-24)

### :bug: Fixes

* **schema:** keep SiteFileUrl parseable on the PHP 8.3 floor ([74ac169](https://git.ole-hartwig.eu/ai-ready-platform/platform/structured-content/commit/74ac169ca6f9d1131fc93abec3b7d4006225a2ae))
* **schema:** resolve file URLs against the site origin, not the language base ([c84dbb1](https://git.ole-hartwig.eu/ai-ready-platform/platform/structured-content/commit/c84dbb1a7fc5238aaada93b17cf381627aeb890d))

### :zap: Refactor

* **schema:** take the file base from Site::getBase() ([f9e895d](https://git.ole-hartwig.eu/ai-ready-platform/platform/structured-content/commit/f9e895daf64fe36ced51482d54b885cc026b62af))

### :white_check_mark: Tests

* **schema:** cover every branch of SiteFileUrl ([6d454fa](https://git.ole-hartwig.eu/ai-ready-platform/platform/structured-content/commit/6d454fa7c6c147cc20b296d14b4a0f9ba342e736))

### :repeat: Chores

* **deps:** update dependency registry.ole-hartwig.eu/devops/images/franken-php/ci to v3.2.365-8.5 ([ab011bb](https://git.ole-hartwig.eu/ai-ready-platform/platform/structured-content/commit/ab011bbab676c47d022c026ac6d02d6d65395963))
* **repo-templates:** sync ([87d92af](https://git.ole-hartwig.eu/ai-ready-platform/platform/structured-content/commit/87d92af137e014b9a2fb4d1ac9f45c83bf4dc495))

## [2.14.11](https://git.ole-hartwig.eu/ai-ready-platform/platform/structured-content/compare/v2.14.10...v2.14.11) (2026-11-19)

### :bug: Fixes

* **jsonld:** a generator's own dates win over the page's ([2018040](https://git.ole-hartwig.eu/ai-ready-platform/platform/structured-content/commit/201804041173875691a1279ce4dba9aa32b23780))

### :white_check_mark: Tests

* **jsonld:** keep the regression test parseable on PHP 8.3 ([880bb24](https://git.ole-hartwig.eu/ai-ready-platform/platform/structured-content/commit/880bb246672c87c35ee5eb2689da802cbe53655b))

### :repeat: Chores

* **deps:** update dependency registry.ole-hartwig.eu/devops/images/franken-php/ci to v3.2.246-8.5 ([147b47b](https://git.ole-hartwig.eu/ai-ready-platform/platform/structured-content/commit/147b47b736722af65462daec640a32c4de550d8e))

## [2.14.10](https://git.ole-hartwig.eu/ai-ready-platform/platform/structured-content/compare/v2.14.9...v2.14.10) (2026-11-10)

### :bug: Fixes

* **schema:** read episode meta of a translated podcast plugin from the translation ([6aea2c1](https://git.ole-hartwig.eu/ai-ready-platform/platform/structured-content/commit/6aea2c1afa588ed8c2a26fb693417b030c892918))
* **schema:** emit schema for free-mode content of translated pages ([6af545a](https://git.ole-hartwig.eu/ai-ready-platform/platform/structured-content/commit/6af545a37fb4433d1dbff580736a5d5125658283))

## [2.14.9](https://git.ole-hartwig.eu/ai-ready-platform/platform/structured-content/compare/v2.14.8...v2.14.9) (2026-11-10)

### :bug: Fixes

* **schema:** read inline children of translated elements from the translation ([1ae70f6](https://git.ole-hartwig.eu/ai-ready-platform/platform/structured-content/commit/1ae70f64e828b50b70b12d6b4ded46054d8a25ee))

## [2.14.8](https://git.ole-hartwig.eu/ai-ready-platform/platform/structured-content/compare/v2.14.7...v2.14.8) (2026-11-09)

### :bug: Fixes

* **tca:** keep the parent link of about entries ([e5c889f](https://git.ole-hartwig.eu/ai-ready-platform/platform/structured-content/commit/e5c889f0e8888fc335291a422227bf39e3d408dc))

### :repeat: Chores

* **repo-templates:** sync ([0aa3601](https://git.ole-hartwig.eu/ai-ready-platform/platform/structured-content/commit/0aa36012ac81d067e22baf54febe84da2e70ff84))
* **deps:** update dependency registry.ole-hartwig.eu/devops/images/franken-php/ci to v3.2.173-8.5 ([545233e](https://git.ole-hartwig.eu/ai-ready-platform/platform/structured-content/commit/545233ed77f05651a5b8a2577a53bae0500b9f9a))
* **deps:** update dependency registry.ole-hartwig.eu/devops/images/franken-php/ci to v3.2.172-8.5 ([61f9e72](https://git.ole-hartwig.eu/ai-ready-platform/platform/structured-content/commit/61f9e72d86786ac71eec8eb1b52ac45964031959))
* **deps:** update dependency registry.ole-hartwig.eu/devops/images/franken-php/ci to v3.2.171-8.5 ([1b3d4b8](https://git.ole-hartwig.eu/ai-ready-platform/platform/structured-content/commit/1b3d4b8b34c7872513dc729d94607febc93ea958))

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

