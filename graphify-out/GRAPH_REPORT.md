# Graph Report - CBT  (2026-11-06)

## Corpus Check
- 233 files · ~231,575 words
- Verdict: corpus is large enough that graph structure adds value.

## Summary
- 1778 nodes · 2988 edges · 149 communities (121 shown, 28 thin omitted)
- Extraction: 97% EXTRACTED · 3% INFERRED · 0% AMBIGUOUS · INFERRED: 96 edges (avg confidence: 0.85)
- Token cost: 0 input · 0 output

## Community Hubs (Navigation)
- Backend Scheduled Commands
- Database Schema Migrations
- Bank Soal Frontend Management
- Master Data Management View
- Frontend Architecture Specification
- Master Data CRUD Controllers
- Backend Architecture Specification
- Exam Monitoring & Anti-Cheat
- Exam Scheduling & Packages
- Student Exam Interface & Autosave
- PDF Report & Exam Administration
- Banksoal Module
- Master Data CRUD Controllers
- Development Roadmap & Stages
- Exam Schedule & Session Plotting
- CBT System Architecture & Rules
- Authentication & Device Sessions
- Exam Session & Login Control
- Exam Monitoring & Anti-Cheat
- Question Bank Backend Controller
- Authentication & Device Sessions
- Bank Soal Frontend Management
- Role Authorization Services
- Word Template Import & Export
- PDF Report & Exam Administration
- Tailwind Styling Best Practices
- Tsconfig.Node Module
- Backend Architecture Specification
- Agents Module
- Exam Scoring & Grading Services
- Claude Module
- Berandasiswaview Module
- Frontend Architecture Specification
- Student Exam API Controller
- Automated Testing Guidelines
- Staflayout Module
- Tsconfig.App Module
- KaTeX Formula Rendering
- Backend Scheduled Commands
- Authentication & Device Sessions
- Word Template Import & Export
- Exam Scheduling & Packages
- Authentication & Device Sessions
- Backend Scheduled Commands
- Authentication & Device Sessions
- Automated Testing Guidelines
- KaTeX Formula Rendering
- Composer Module
- Database Schema Migrations
- Authentication & Device Sessions
- Authentication & Device Sessions
- Offlinequeue Module
- Ruangtungguview Module
- Authentication & Device Sessions
- Tailwind Styling Best Practices
- Queue Jobs Module
- Automated Testing Guidelines
- Role Authorization Services
- Advanced Queries Module
- Events Notifications Module
- Database Schema Migrations
- Composer Module
- Composer Module
- Loginview Module
- Operatorkontrolcontroller Module
- Appserviceprovider Module
- Caching Module
- Db Performance Module
- Eloquent Module
- Composer Module
- Readme Module
- Ujianselesaiview Module
- Checklists Module
- Blade Views Module
- Error Handling Module
- Scheduling Module
- Automated Testing Guidelines
- Automated Testing Guidelines
- Composer Module
- Automated Testing Guidelines
- Monitoring Module
- Berandastafview Module
- Collections Module
- Automated Testing Guidelines
- Automated Testing Guidelines
- Routing Module
- Style Module
- Validation Module
- Automated Testing Guidelines
- Automated Testing Guidelines
- Automated Testing Guidelines
- Fase3 Module
- Automated Testing Guidelines
- Config Module
- Skill Module
- Automated Testing Guidelines
- Automated Testing Guidelines
- Composer Module
- Logging Module
- Authentication & Device Sessions
- KaTeX Formula Rendering
- Automated Testing Guidelines
- Bank Soal Frontend Management
- Automated Testing Guidelines
- Automated Testing Guidelines
- Echo Module
- Authentication & Device Sessions
- Composer Module
- Composer Module
- .Mcp Module
- Console Module
- Env.D Module
- Automated Testing Guidelines
- Authentication & Device Sessions
- Bank Soal Frontend Management
- Tsconfig Module
- Automated Testing Guidelines
- Tailwind Styling Best Practices
- Package Module
- Package Module
- Automated Testing Guidelines
- Package Module
- Automated Testing Guidelines
- Robots Module
- Index Module
- Banner Module
- Favicon Module
- Icons Module
- Illustration Books Module
- Login Bg Module
- Login Page Module
- Readme Module

## God Nodes (most connected - your core abstractions)
1. `User` - 78 edges
2. `PesertaUjian` - 58 edges
3. `BankSoal` - 49 edges
4. `Requirement Frontend: Website Ujian Sekolah (CBT)` - 43 edges
5. `Guru` - 42 edges
6. `Requirement Backend: Website Ujian Sekolah (CBT)` - 40 edges
7. `GuruMapel` - 39 edges
8. `MasterDataController` - 35 edges
9. `JadwalUjian` - 35 edges
10. `Kelas` - 34 edges

## Surprising Connections (you probably didn't know these)
- `deleteSoal()` --calls--> `confirm()`  [INFERRED]
  frontend/src/views/staf/BankSoalView.vue → frontend/src/components/ConfirmLogoutModal.vue
- `deleteEntity()` --calls--> `confirm()`  [INFERRED]
  frontend/src/views/staf/MasterDataView.vue → frontend/src/components/ConfirmLogoutModal.vue
- `AdministrasiUjianController` --inherits--> `Controller`  [EXTRACTED]
  backend/app/Http/Controllers/AdministrasiUjianController.php → backend/app/Http/Controllers/Controller.php
- `AdministrasiUjianController` --references--> `AuditLogService`  [EXTRACTED]
  backend/app/Http/Controllers/AdministrasiUjianController.php → backend/app/Services/AuditLogService.php
- `AdministrasiUjianController` --references--> `OtorisasiCbtService`  [EXTRACTED]
  backend/app/Http/Controllers/AdministrasiUjianController.php → backend/app/Services/OtorisasiCbtService.php

## Import Cycles
- None detected.

## Communities (149 total, 28 thin omitted)

### Community 0 - "Backend Scheduled Commands"
Cohesion: 0.06
Nodes (21): BackupDatabase, SubmitPesertaKedaluwarsa, ForceLogout, LockThresholdReached, PesertaLocked, PesertaProgress, PesertaStatusChanged, PesertaSubmitted (+13 more)

### Community 1 - "Database Schema Migrations"
Cohesion: 0.06
Nodes (3): Illuminate\Database\Migrations\Migration, Illuminate\Database\Schema\Blueprint, Illuminate\Support\Facades\Schema

### Community 2 - "Bank Soal Frontend Management"
Cohesion: 0.04
Nodes (38): auth, canManageSelectedMapel, editId, errorMessage, feedbackMessage, filteredSoal, formKonten, formMapelId (+30 more)

### Community 3 - "Master Data Management View"
Cohesion: 0.05
Nodes (38): activeTab, auth, commitExcel(), currentListCount, deleteEntity(), editId, errorMessage, feedbackMessage (+30 more)

### Community 4 - "Frontend Architecture Specification"
Cohesion: 0.05
Nodes (44): 1. Ringkasan, 2. Stack, 3.1 Umum, 3.2 Siswa, 3.3 Operator, 3.4 Guru, 3.5 Superadmin, 3. Sitemap per Role (+36 more)

### Community 5 - "Master Data CRUD Controllers"
Cohesion: 0.08
Nodes (14): Kelas, Mapel, Ruang, Siswa, TahunAjaran, DatabaseSeeder, Fase1Test, Illuminate\Database\Seeder (+6 more)

### Community 6 - "Backend Architecture Specification"
Cohesion: 0.05
Nodes (41): 10. Tahapan, 1. Ringkasan, 2. Stack, 3.1 Role, 3.2 Matriks Akses, 3.3 Aturan Scope Guru, 3. Role dan Otorisasi, 4.10 Nilai dan Laporan (+33 more)

### Community 7 - "Exam Monitoring & Anti-Cheat"
Cohesion: 0.08
Nodes (12): AutosaveJawabanRequest, BukaKunciPesertaRequest, KoreksiEssayRequest, KunciUjianRequest, LoginRequest, ManageUserRequest, SimpanBeritaAcaraRequest, UpdateAmbangKunciRequest (+4 more)

### Community 8 - "Exam Scheduling & Packages"
Cohesion: 0.08
Nodes (8): BobotTipe, GuruMapel, JadwalUjian, OpsiSoal, PaketSoal, PaketSoalItem, Illuminate\Support\Facades\Queue, PhpOffice\PhpSpreadsheet\IOFactory

### Community 9 - "Student Exam Interface & Autosave"
Cohesion: 0.07
Nodes (32): answers, belumTerjawabCount, clientSeq, currentIndex, currentSoal, formattedTimer, goToQuestion(), isSoalAnswered() (+24 more)

### Community 10 - "PDF Report & Exam Administration"
Cohesion: 0.12
Nodes (12): GenerateLaporanPdf, ProsesPenilaianPeserta, LaporanPdf, PenilaianService, Dompdf\Dompdf, Dompdf\Options, Illuminate\Bus\Queueable, Illuminate\Contracts\Queue\ShouldQueue (+4 more)

### Community 11 - "Banksoal Module"
Cohesion: 0.12
Nodes (6): DeviceSession, LockEvent, Media, Illuminate\Database\Eloquent\Factories\HasFactory, Illuminate\Database\Eloquent\Model, Illuminate\Database\Eloquent\SoftDeletes

### Community 13 - "Development Roadmap & Stages"
Cohesion: 0.06
Nodes (33): 1.1 Autentikasi & Manajemen Pengguna, 1.2 Master Data & Impor Excel, 1.3 Bank Soal, Unggah Media, & Impor Word, 1.4 Paket Soal, Bobot, Jadwal, & Kontrol Operator, 1.5 Pengerjaan Ujian Siswa  & Penilaian Otomatis, 2.1 Kunci Sesi, 2.2 Realtime Monitoring & Dashboard Pengawas, 2.3 Koreksi Essay & Rekap Nilai Guru Mapel (+25 more)

### Community 14 - "Exam Schedule & Session Plotting"
Cohesion: 0.07
Nodes (29): activeTab, availablePaketForJadwal, bankSoalList, bobotList, editBobot, errorMessage, feedbackMessage, fetchAll() (+21 more)

### Community 15 - "CBT System Architecture & Rules"
Cohesion: 0.06
Nodes (31): 10. Keputusan yang Sudah Diambil, 11. Langkah Selanjutnya, 1. Ringkasan Proyek, 2. Role Pengguna, 3. Jenis Soal dan Penilaian, 4.1 Alur Ujian, 4.2 Alur Sistem, 4.3 Aturan Kunci Sesi (+23 more)

### Community 16 - "Authentication & Device Sessions"
Cohesion: 0.14
Nodes (5): User, AuthTest, Fase3Test, Illuminate\Foundation\Auth\User, Illuminate\Foundation\Testing\RefreshDatabase

### Community 17 - "Exam Session & Login Control"
Cohesion: 0.10
Nodes (25): confirm(), deletePaket(), actionLoading, allSessions, auth, bukaKunciPeserta(), errorMessage, feedbackMessage (+17 more)

### Community 18 - "Exam Monitoring & Anti-Cheat"
Cohesion: 0.16
Nodes (4): PengawasanController, UjianController, PesertaUjian, Carbon

### Community 19 - "Question Bank Backend Controller"
Cohesion: 0.13
Nodes (4): BankSoalController, BankSoal, HtmlSanitizerService, DOMNode

### Community 20 - "Authentication & Device Sessions"
Cohesion: 0.08
Nodes (24): Application Setup, Authentication System, Billing and Usage, Build and Deploy, CLI Flags, Cloud CLI, Commands, Config (+16 more)

### Community 21 - "Bank Soal Frontend Management"
Cohesion: 0.12
Nodes (7): berandaUntukRole(), GuardResult, resolveGuard(), RouteAccessMeta, RouteMeta, router, vue-router

### Community 22 - "Role Authorization Services"
Cohesion: 0.14
Nodes (5): JawabanSiswa, PengawasSesi, Fase2Test, Illuminate\Support\Facades\Broadcast, Illuminate\Support\Facades\Event

### Community 23 - "Word Template Import & Export"
Cohesion: 0.14
Nodes (6): ExcelService, WordSoalService, Illuminate\Http\UploadedFile, PhpOffice\PhpWord\IOFactory, PhpOffice\PhpWord\PhpWord, ZipArchive

### Community 24 - "PDF Report & Exam Administration"
Cohesion: 0.15
Nodes (6): AnalisisButirController, LaporanPdfController, NilaiController, OtorisasiCbtService, Illuminate\Support\Facades\Route, Symfony\Component\HttpFoundation\StreamedResponse

### Community 25 - "Tailwind Styling Best Practices"
Cohesion: 0.10
Nodes (20): devDependencies, concurrently, laravel-vite-plugin, tailwindcss, @tailwindcss/vite, vite, tailwindcss, @tailwindcss/vite (+12 more)

### Community 26 - "Tsconfig.Node Module"
Cohesion: 0.10
Nodes (19): compilerOptions, allowImportingTsExtensions, erasableSyntaxOnly, lib, module, moduleDetection, noEmit, noFallthroughCasesInSwitch (+11 more)

### Community 27 - "Backend Architecture Specification"
Cohesion: 0.10
Nodes (20): 0. Prinsip Dasar, 1. Keamanan dan Kerahasiaan Soal/Nilai, 2. Aturan Ujian yang Tidak Boleh Diubah, 3. Penilaian, 4. Impor/Ekspor, 5. Data dan Database, 6. Kinerja, 7. Log dan Audit (+12 more)

### Community 28 - "Agents Module"
Cohesion: 0.11
Nodes (19): APIs & Eloquent Resources, Application Structure & Architecture, Artisan, Conventions, Laravel Boost Guidelines, Documentation Files, Foundational Context, Frontend Bundling (+11 more)

### Community 29 - "Exam Scoring & Grading Services"
Cohesion: 0.13
Nodes (7): Nilai, ApiFoundationTest, BackupCommandTest, ExampleTest, TestCase, Illuminate\Foundation\Testing\TestCase, Illuminate\Support\Facades\Storage

### Community 30 - "Claude Module"
Cohesion: 0.11
Nodes (19): APIs & Eloquent Resources, Application Structure & Architecture, Artisan, Conventions, Laravel Boost Guidelines, Documentation Files, Foundational Context, Frontend Bundling (+11 more)

### Community 31 - "Berandasiswaview Module"
Cohesion: 0.11
Nodes (11): auth, errorMessage, isLoggingOut, loadingJoin, loadingSesi, router, selectedSesi, SesiItem (+3 more)

### Community 32 - "Frontend Architecture Specification"
Cohesion: 0.11
Nodes (19): 0. Prinsip Dasar, 1. Kerahasiaan dan Keamanan, 2. Aturan Ujian yang Tidak Boleh Diubah, 3. Autosave, 4. Impor/Ekspor, 5. Kinerja dan Kompatibilitas, 6. UX dan Aksesibilitas, 7. Kualitas Kode dan Proses (+11 more)

### Community 33 - "Student Exam API Controller"
Cohesion: 0.14
Nodes (3): AdministrasiUjianController, BeritaAcara, LogAktivitas

### Community 35 - "Staflayout Module"
Cohesion: 0.11
Nodes (12): auth, isCollapsed, isGuruOrSuperadmin, isLoggingOut, isOperatorOrSuperadmin, mobileOpen, NavItem, navItems (+4 more)

### Community 36 - "Tsconfig.App Module"
Cohesion: 0.11
Nodes (17): compilerOptions, allowArbitraryExtensions, erasableSyntaxOnly, noFallthroughCasesInSwitch, noUnusedLocals, noUnusedParameters, paths, strict (+9 more)

### Community 37 - "KaTeX Formula Rendering"
Cohesion: 0.12
Nodes (17): axios, dependencies, axios, idb, katex, laravel-echo, pinia, pusher-js (+9 more)

### Community 38 - "Backend Scheduled Commands"
Cohesion: 0.20
Nodes (5): KoreksiEssayController, AuditLogService, Carbon\Carbon, Illuminate\Support\Facades\Cache, Illuminate\Support\Facades\DB

### Community 39 - "Authentication & Device Sessions"
Cohesion: 0.17
Nodes (4): AuthController, Controller, PengaturanController, Pengaturan

### Community 40 - "Word Template Import & Export"
Cohesion: 0.16
Nodes (4): ExcelController, WordSoalController, Exception, Symfony\Component\HttpFoundation\BinaryFileResponse

### Community 41 - "Exam Scheduling & Packages"
Cohesion: 0.20
Nodes (3): JadwalController, TokenUjian, Illuminate\Http\Request

### Community 42 - "Authentication & Device Sessions"
Cohesion: 0.14
Nodes (9): UserFactory, Illuminate\Database\Eloquent\Attributes\Fillable, Illuminate\Database\Eloquent\Attributes\Hidden, Illuminate\Database\Eloquent\Factories\Factory, Illuminate\Notifications\Notifiable, Illuminate\Support\Str, Laravel\Sanctum\HasApiTokens, Pdo\Mysql (+1 more)

### Community 43 - "Backend Scheduled Commands"
Cohesion: 0.13
Nodes (12): EnsureRole, Closure, Illuminate\Auth\Access\AuthorizationException, Illuminate\Auth\AuthenticationException, Illuminate\Console\Scheduling\Schedule, Illuminate\Foundation\Application, Illuminate\Foundation\Configuration\Exceptions, Illuminate\Foundation\Configuration\Middleware (+4 more)

### Community 44 - "Authentication & Device Sessions"
Cohesion: 0.17
Nodes (7): apiClient, ApiError, kodeDariStatus(), normalizeError(), UnauthenticatedHandler, PESAN, pesanError()

### Community 45 - "Automated Testing Guidelines"
Cohesion: 0.13
Nodes (15): scripts, dev, post-create-project-cmd, post-update-cmd, pre-package-uninstall, test, Composer\\Config::disableProcessTimeout, Illuminate\\Foundation\\ComposerScripts::prePackageUninstall (+7 more)

### Community 46 - "KaTeX Formula Rendering"
Cohesion: 0.13
Nodes (15): devDependencies, jsdom, tailwindcss, @types/katex, @types/node, typescript, vitest, @vue/tsconfig (+7 more)

### Community 47 - "Composer Module"
Cohesion: 0.14
Nodes (13): autoload-dev, psr-4, description, extra, laravel, dont-discover, license, minimum-stability (+5 more)

### Community 48 - "Database Schema Migrations"
Cohesion: 0.17
Nodes (12): A. Validation & HTTP input, Authentication System, B. Controllers & routing, C. Authorization, D. Eloquent & models, Detection checklist, E. Architecture & organization, F. Frontend & views (+4 more)

### Community 49 - "Authentication & Device Sessions"
Cohesion: 0.17
Nodes (12): Authentication System, Infer conventions, Edge cases, Glob mapping, Ground rules, Process, Step 0: Orient, Step 1: Predefined sweep (+4 more)

### Community 50 - "Authentication & Device Sessions"
Cohesion: 0.17
Nodes (12): Authentication System, Depend on contracts at boundaries, Architecture best practices, Extract focused business operations, Follow framework conventions, Inject required dependencies, Specify a deterministic sort order, Use atomic locks for race conditions (+4 more)

### Community 51 - "Offlinequeue Module"
Cohesion: 0.27
Nodes (11): ambilJawaban(), ambilLock(), antrekanJawaban(), antrekanLock(), bersihkanSemua(), CbtDB, db(), JawabanAntrean (+3 more)

### Community 52 - "Ruangtungguview Module"
Cohesion: 0.17
Nodes (8): auth, errorMessage, examState, isLoggingOut, loading, router, showLogoutConfirm, ujianStore

### Community 53 - "Authentication & Device Sessions"
Cohesion: 0.18
Nodes (11): Apply cross-site request forgery protection, Audit dependencies, Authorize protected actions, Bind query parameters, Control mass assignment, Security best practices, Encrypt sensitive attributes when appropriate, Escape output in its context (+3 more)

### Community 54 - "Tailwind Styling Best Practices"
Cohesion: 0.18
Nodes (11): Basic usage, Common pitfalls, CSS-first configuration, Dark mode, Tailwind CSS development, Documentation, Import syntax, Replaced utilities (+3 more)

### Community 55 - "Queue Jobs Module"
Cohesion: 0.20
Nodes (10): Back off transient failures, Batch jobs for group coordination, Configure time-based retry limits deliberately, Queue and job best practices, Handle terminal failure when needed, Keep reservation time longer than execution time, Role Operator, Rate limit external calls (+2 more)

### Community 56 - "Automated Testing Guidelines"
Cohesion: 0.20
Nodes (9): name, private, scripts, build, dev, preview, test, type (+1 more)

### Community 58 - "Advanced Queries Module"
Cohesion: 0.22
Nodes (9): Combine related counts with conditional aggregates, Compare whereHas with an IN subquery, Consider a correlated subquery for has-many ordering, Create dynamic relationships with a subquery foreign key, Design composite indexes for the query, Advanced query best practices, Measure two simple queries against one complex query, Reuse loaded parent models with setRelation (+1 more)

### Community 59 - "Events Notifications Module"
Cohesion: 0.22
Nodes (9): Cache event discovery during production deployment, Dispatch queued notifications after commit, Events and notifications best practices, Implement HasLocalePreference on notifiable models, Queue slow notifications, Rely on event discovery, Route notification channels to dedicated queues, Use on-demand notifications for non-user recipients (+1 more)

### Community 60 - "Database Schema Migrations"
Cohesion: 0.22
Nodes (9): Define foreign-key constraints deliberately, Design indexes for real queries, Migration best practices, Generate migrations with Artisan, Keep migrations focused, Make rollbacks honest, Mirror defaults only when unsaved models need them, Stage changes that affect existing rows (+1 more)

### Community 61 - "Composer Module"
Cohesion: 0.22
Nodes (9): require, dompdf/dompdf, laravel/framework, laravel/reverb, laravel/sanctum, laravel/tinker, maatwebsite/excel, php (+1 more)

### Community 62 - "Composer Module"
Cohesion: 0.22
Nodes (9): require-dev, fakerphp/faker, laravel/boost, laravel/pail, laravel/pao, laravel/pint, mockery/mockery, nunomaduro/collision (+1 more)

### Community 63 - "Loginview Module"
Cohesion: 0.22
Nodes (7): auth, errorMessage, loading, password, router, showPassword, username

### Community 65 - "Appserviceprovider Module"
Cohesion: 0.29
Nodes (4): AppServiceProvider, Illuminate\Cache\RateLimiting\Limit, Illuminate\Support\Facades\RateLimiter, Illuminate\Support\ServiceProvider

### Community 66 - "Caching Module"
Cohesion: 0.25
Nodes (8): Configure failover cache stores in production, Consider Cache::flexible for stale-while-revalidate, Caching best practices, Use Cache::add for atomic conditional writes, Use Cache::memo to avoid redundant hits within an execution, Use Cache::remember for cache-aside reads, Use cache tags to invalidate related groups, Use once for in-process memoization

### Community 67 - "Db Performance Module"
Cohesion: 0.25
Nodes (8): Add indexes for measured query patterns, Count relationships without loading them, Database performance best practices, Eager load relationships before iterating, Keep queries out of Blade templates, Prevent lazy loading in development, Process large data sets incrementally, Select only the columns you need

### Community 68 - "Eloquent Module"
Cohesion: 0.25
Nodes (8): Apply global scopes sparingly, Cast date and time attributes, Define attribute casts, Define precise relationship types, Eloquent best practices, Keep application queries model-aware, Use local scopes for reusable queries, Use whereBelongsTo for relationship queries

### Community 69 - "Composer Module"
Cohesion: 0.25
Nodes (8): post-root-package-install, setup, composer install, npm install --ignore-scripts, npm run build, @php artisan key:generate, @php artisan migrate --force, @php -r \"file_exists('.env') || copy('.env.example', '.env');\

### Community 70 - "Readme Module"
Cohesion: 0.25
Nodes (8): About Laravel, Agentic Development, Code of Conduct, Contributing, Readme, Learning Laravel, License, Security Vulnerabilities

### Community 71 - "Ujianselesaiview Module"
Cohesion: 0.25
Nodes (4): auth, isLoggingOut, router, showLogoutConfirm

### Community 72 - "Checklists Module"
Cohesion: 0.29
Nodes (7): Adding a cache to an existing environment, Adding a database to an existing environment, Custom domain setup, Checklists for Multi-Step Operations, Full environment setup, New app from scratch, Sending email with Resend

### Community 73 - "Blade Views Module"
Cohesion: 0.29
Nodes (7): Blade and view best practices, Prefer components for explicit interfaces, Return Blade fragments for partial rendering, Share compatible view data with a view composer, Share parent component props with @aware, Use $attributes->merge in component templates, Use @pushOnce for per-component scripts

### Community 74 - "Error Handling Module"
Cohesion: 0.29
Nodes (7): Add context to exception classes, Choose where to report and render exceptions, Define JSON rendering for API routes, Error handling best practices, Mark exceptions the handler should not report, Prevent duplicate reports of one exception instance, Throttle high-volume exception reports

### Community 75 - "Scheduling Module"
Cohesion: 0.29
Nodes (7): Bound work inside the task, Task scheduling best practices, Group shared configuration, Prevent unwanted overlap, Restrict tasks by environment, Run a task on one server, Run eligible commands in the background

### Community 76 - "Automated Testing Guidelines"
Cohesion: 0.29
Nodes (7): Endpoint tests, Endpoint coverage, How to write the test, Tenant isolation, Test authorization at the policy level, Testing validation, Which layer owns which case

### Community 77 - "Automated Testing Guidelines"
Cohesion: 0.29
Nodes (7): Database, Fakes, mocks, and determinism, Framework fakes, How to isolate a dependency, Mocking, Outbound HTTP testing, Time and randomness

### Community 78 - "Composer Module"
Cohesion: 0.29
Nodes (7): pestphp/pest-plugin, php-http/discovery, config, allow-plugins, optimize-autoloader, preferred-install, sort-packages

### Community 79 - "Automated Testing Guidelines"
Cohesion: 0.29
Nodes (7): Antrean Redis, Backup database, Operasional Backend CBT, Load test 700 siswa, Role Operator, Recovery drill, Role Siswa

### Community 80 - "Monitoring Module"
Cohesion: 0.38
Nodes (5): KoneksiRealtime, PesertaMonitoring, useMonitoringStore, PesertaStatus, useUjianStore

### Community 81 - "Berandastafview Module"
Cohesion: 0.29
Nodes (5): auth, isGuruOrSuperadmin, isOperatorOrSuperadmin, role, stats

### Community 82 - "Collections Module"
Cohesion: 0.33
Nodes (6): Choose between cursor and lazy, Collection best practices, Use # for custom collection classes, Use higher-order messages for simple operations, Use lazyById when updating records while iterating, Use toQuery for bulk operations on collections

### Community 83 - "Automated Testing Guidelines"
Cohesion: 0.33
Nodes (6): HTTP client best practices, Fake HTTP requests in tests, Handle errors explicitly, Pool independent requests, Retry only safe operations, Set explicit timeouts

### Community 84 - "Automated Testing Guidelines"
Cohesion: 0.33
Nodes (6): Assert the delivery mode, Dispatch queued mail after commit, Mail best practices, Queue slow mail delivery, Separate content and delivery tests, Use Markdown mailables when they fit

### Community 85 - "Routing Module"
Cohesion: 0.33
Nodes (6): Routing and controller best practices, Keep controllers focused on HTTP concerns, Organize controllers around resources, Scope nested bindings, Use implicit route model binding, Use resource routes for resourceful actions

### Community 86 - "Style Module"
Cohesion: 0.33
Nodes (6): Convention and style best practices, Follow project naming conventions, Keep presentation code maintainable, Prefer clear, idiomatic syntax, Use utilities when they clarify intent, Write comments that explain why

### Community 87 - "Validation Module"
Cohesion: 0.33
Nodes (6): Add cross-field validation after base rules, Validation and forms best practices, Express conditional rules clearly, Extract validation when it improves the boundary, Prefer readable rule syntax, Use only the validated data you intend

### Community 88 - "Automated Testing Guidelines"
Cohesion: 0.33
Nodes (6): Arrange, act, assert, Assert a known value, Assert the complete result, Assertions, How to find the correct assertion, Named response assertions

### Community 89 - "Automated Testing Guidelines"
Cohesion: 0.33
Nodes (6): Common errors, Test suite performance, Global fakes, How to find a slow test, How to run the suite in parallel, Test environment

### Community 90 - "Automated Testing Guidelines"
Cohesion: 0.33
Nodes (6): Assertions, Coverage, Data and determinism, Reviewing tests, Names and structure, Test value

### Community 91 - "Fase3 Module"
Cohesion: 0.33
Nodes (3): baseUrl, options, users

### Community 92 - "Automated Testing Guidelines"
Cohesion: 0.47
Nodes (4): close(), emit, handleKeydown(), props

### Community 93 - "Config Module"
Cohesion: 0.40
Nodes (5): Configuration best practices, Name repeated domain values, Protect production secrets, Read environment variables in configuration files, Use App::environment for environment checks

### Community 94 - "Skill Module"
Cohesion: 0.40
Nodes (5): Consistency first, Decision rules, Laravel best practices, How to apply, Rule index

### Community 95 - "Automated Testing Guidelines"
Cohesion: 0.40
Nodes (5): Naming and structure, File layout, Grouping, Naming tests, Test class and methods

### Community 96 - "Automated Testing Guidelines"
Cohesion: 0.40
Nodes (5): Consistency first, Testing best practices, How to apply, Rule index, What to test

### Community 97 - "Composer Module"
Cohesion: 0.40
Nodes (5): autoload, psr-4, App\\, Database\\Factories\\, Database\\Seeders\\

### Community 98 - "Logging Module"
Cohesion: 0.40
Nodes (4): Monolog\Handler\NullHandler, Monolog\Handler\StreamHandler, Monolog\Handler\SyslogUdpHandler, Monolog\Processor\PsrLogMessageProcessor

### Community 99 - "Authentication & Device Sessions"
Cohesion: 0.40
Nodes (4): Illuminate\Cookie\Middleware\EncryptCookies, Illuminate\Foundation\Http\Middleware\ValidateCsrfToken, Laravel\Sanctum\Http\Middleware\AuthenticateSession, Laravel\Sanctum\Sanctum

### Community 100 - "KaTeX Formula Rendering"
Cohesion: 0.70
Nodes (3): cache, renderLatex(), renderMathInHtml()

### Community 101 - "Automated Testing Guidelines"
Cohesion: 0.70
Nodes (3): formatDurasi(), hitungOffset(), sisaDetik()

### Community 102 - "Bank Soal Frontend Management"
Cohesion: 0.40
Nodes (5): commitWord(), deleteSoal(), fetchInitial(), fetchSoal(), saveSoal()

### Community 103 - "Automated Testing Guidelines"
Cohesion: 0.50
Nodes (4): Data providers, Factories and test data, Each test makes its own data, Record construction

### Community 106 - "Authentication & Device Sessions"
Cohesion: 0.50
Nodes (3): ApiErrorBody, AuthUser, Role

### Community 107 - "Composer Module"
Cohesion: 0.67
Nodes (3): keywords, framework, laravel

### Community 108 - "Composer Module"
Cohesion: 0.67
Nodes (3): post-autoload-dump, Illuminate\\Foundation\\ComposerScripts::postAutoloadDump, @php artisan package:discover --ansi

### Community 114 - "Bank Soal Frontend Management"
Cohesion: 0.67
Nodes (3): handleTipeChange(), initOpsiForType(), openCreateModal()

## Knowledge Gaps
- **794 isolated node(s):** `php`, `$schema`, `name`, `type`, `description` (+789 more)
  These have ≤1 connection - possible missing edges or undocumented components.
- **28 thin communities (<3 nodes) omitted from report** — run `graphify query` to explore isolated nodes.

## Suggested Questions
_Questions this graph is uniquely positioned to answer:_

- **Why does `User` connect `Authentication & Device Sessions` to `Student Exam API Controller`, `Automated Testing Guidelines`, `Master Data CRUD Controllers`, `Backend Scheduled Commands`, `Authentication & Device Sessions`, `Exam Scheduling & Packages`, `Exam Monitoring & Anti-Cheat`, `Authentication & Device Sessions`, `Banksoal Module`, `Master Data CRUD Controllers`, `Exam Monitoring & Anti-Cheat`, `Role Authorization Services`, `Role Authorization Services`, `Exam Scoring & Grading Services`?**
  _High betweenness centrality (0.019) - this node is a cross-community bridge._
- **Why does `PesertaUjian` connect `Exam Monitoring & Anti-Cheat` to `Backend Scheduled Commands`, `Operatorkontrolcontroller Module`, `Master Data CRUD Controllers`, `Backend Scheduled Commands`, `Exam Scheduling & Packages`, `Exam Scheduling & Packages`, `PDF Report & Exam Administration`, `Banksoal Module`, `Authentication & Device Sessions`, `Question Bank Backend Controller`, `Role Authorization Services`, `PDF Report & Exam Administration`, `Exam Scoring & Grading Services`?**
  _High betweenness centrality (0.010) - this node is a cross-community bridge._
- **Why does `BankSoal` connect `Question Bank Backend Controller` to `Operatorkontrolcontroller Module`, `Automated Testing Guidelines`, `Master Data CRUD Controllers`, `Backend Scheduled Commands`, `Exam Scheduling & Packages`, `Exam Scheduling & Packages`, `Word Template Import & Export`, `Banksoal Module`, `PDF Report & Exam Administration`, `Authentication & Device Sessions`, `Exam Monitoring & Anti-Cheat`, `Role Authorization Services`, `Word Template Import & Export`, `PDF Report & Exam Administration`, `Exam Scoring & Grading Services`?**
  _High betweenness centrality (0.008) - this node is a cross-community bridge._
- **What connects `php`, `$schema`, `name` to the rest of the system?**
  _794 weakly-connected nodes found - possible documentation gaps or missing edges._
- **Should `Backend Scheduled Commands` be split into smaller, more focused modules?**
  _Cohesion score 0.05738615327656423 - nodes in this community are weakly interconnected._
- **Should `Database Schema Migrations` be split into smaller, more focused modules?**
  _Cohesion score 0.06274509803921569 - nodes in this community are weakly interconnected._
- **Should `Bank Soal Frontend Management` be split into smaller, more focused modules?**
  _Cohesion score 0.041666666666666664 - nodes in this community are weakly interconnected._