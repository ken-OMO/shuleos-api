ShuleOS Engineering Constitution
Final Edition
Version 1.1
128 Locked Engineering Rules
Purpose
This Constitution is the governing standard for all ShuleOS engineering work. Every change to ShuleOS—architecture, database, backend, frontend, infrastructure, security, testing, documentation and AI-generated code—must comply with these rules.
Section A – Core Principles

1. Rule 1: ShuleOS solves real school problems.
2. Rule 2: Security before features.
3. Rule 3: Privacy by Design.
4. Rule 4: Tenant First.
5. Rule 5: Archive First.
6. Rule 6: Consistency over cleverness.
7. Rule 7: Human Experience.
8. Rule 8: Clean Code.
9. Rule 9: Every feature belongs to a Domain.
10. Rule 10: Design first. Code second.
    Section B – Security Rules
11. Rule 11: Every API request is untrusted until identity, tenant, authorization and ownership are verified.
12. Rule 12: Never trust client input.
13. Rule 13: Protect against IDOR.
14. Rule 14: No endpoint bypasses the security pipeline.
15. Rule 15: Prevent SQL Injection.
16. Rule 16: Prevent Mass Assignment.
17. Rule 17: Audit important actions.
18. Rule 18: Never expose internal exceptions.
19. Rule 19: Every security feature is tested.
20. Rule 20: Security review before merge.
    Section C – Database Rules
21. Rule 21: Database first.
22. Rule 22: Every table has primary keys, foreign keys, constraints and indexes.
23. Rule 23: Indexes are reviewed.
24. Rule 24: Every query is reviewed.
25. Rule 25: Use transactions.
26. Rule 26: Every database change includes a rollback plan.
27. Rule 27: Performance is measured, not guessed.
    Section D – Multi-Tenant Rules
28. Rule 28: TenantContext is mandatory.
29. Rule 29: Requests never choose their own tenant.
30. Rule 30: Every query is tenant scoped.
31. Rule 31: Foreign keys respect tenant ownership.
32. Rule 32: Cross-tenant tests are mandatory.
    Section E – Code Rules
33. Rule 33: Thin controllers.
34. Rule 34: Business logic belongs in services.
35. Rule 35: Validation belongs in Form Requests or Zod schemas.
36. Rule 36: No duplicated business rules.
37. Rule 37: No dead code.
38. Rule 38: No commented-out code.
39. Rule 39: No magic strings.
40. Rule 40: Follow naming standards.
    Section F – Frontend Rules
41. Rule 41: Humanized interface.
42. Rule 42: No emojis.
43. Rule 43: Professional language.
44. Rule 44: Simple workflows.
45. Rule 45: Use the approved hybrid rendering strategy.
46. Rule 46: Frontend authorization never replaces backend authorization.
    Section G – Identity & Access Management
47. Rule 47: Platform roles are protected.
48. Rule 48: Schools may create custom roles.
49. Rule 49: Schools receive role templates.
50. Rule 50: Delegated administration.
51. Rule 51: Schools cannot assign platform permissions.
    Section H – Payments
52. Rule 52: Platform billing is separate from school finance.
53. Rule 53: Subscription payments belong to ShuleOS.
54. Rule 54: School fees use school-configured payment channels.
55. Rule 55: Financial operations are idempotent.
56. Rule 56: Every payment is auditable.
    Section I – Notifications
57. Rule 57: Email via Resend.
58. Rule 58: WhatsApp preferred where appropriate.
59. Rule 59: SMS for critical communication.
60. Rule 60: Notification Engine selects the delivery channel.
    Section J – Files
61. Rule 61: Cloudflare R2 for object storage.
62. Rule 62: Document uploads are optional where appropriate.
63. Rule 63: Every file belongs to a tenant.
64. Rule 64: Signed URLs.
65. Rule 65: Files are scanned before permanent storage.
    Section K – Testing
66. Rule 66: Every feature has tests.
67. Rule 67: Security tests are mandatory.
68. Rule 68: Cross-tenant tests are mandatory.
69. Rule 69: Performance tests are mandatory.
70. Rule 70: Rollback tests are mandatory.
    Section L – Git
71. Rule 71: No direct commits to main.
72. Rule 72: Small focused commits.
73. Rule 73: Every pull request is reviewed.
74. Rule 74: No secrets committed.
75. Rule 75: Merge only after acceptance gates pass.
    Section M – AI
76. Rule 76: AI writes code; humans approve architecture.
77. Rule 77: AI-generated code undergoes architecture, security, database, performance and testing review.
78. Rule 78: AI code enters only after proving security, scalability, performance, tenant safety and maintainability.
    Section N – Product
79. Rule 79: Every feature improves the platform.
80. Rule 80: Every feature fits the long-term architecture.
81. Rule 81: No isolated features.
82. Rule 82: Humanized before release.
83. Rule 83: Documentation updated before merge.
84. Rule 84: Every Architecture Decision is recorded as an ADR.
85. Rule 85: The roadmap may evolve; engineering principles do not.
86. Rule 86: Code enters only after being proven secure, reliable, scalable, performant, tenant-safe, maintainable and human-centered.
    Section O – Platform Integrity
87. Rule 87: Tenant isolation is enforced by both the application and the database.
88. Rule 88: Authentication has one source of truth.
89. Rule 89: Authorization depends on authenticated identity and fails closed.
90. Rule 90: Uniqueness is tenant-scoped unless explicitly justified.
91. Rule 91: Secrets follow one approved protection standard.
92. Rule 92: Account-state flags ship with enforcement.
93. Rule 93: Access revocation takes effect on the next request.
    Section P – Consistency Enforcement
94. Rule 94: Every module follows the approved architecture.
95. Rule 95: Remove duplicate abstractions.
96. Rule 96: Generated code is formatted before review.
    Section Q – Schema Truth
97. Rule 97: Migrations define the schema.
98. Rule 98: Tests use the real schema.
99. Rule 99: Tenant-owned tables are complete only when tenant-safe.
    Section R – Financial Integrity
100. Rule 100: Idempotency is enforced using stored keys.
101. Rule 101: Financial ownership is established server-side.
102. Rule 102: Security-critical invariants are verified automatically.
     Section S – Operational Security & Governance
103. Rule 103: Every dependency is regularly scanned and patched according to severity.
104. Rule 104: Every secret has a managed lifecycle including rotation and revocation.
105. Rule 105: Every security incident follows a documented response process.
106. Rule 106: Backups are verified through restore testing.
107. Rule 107: Production systems are observable through logs, metrics and alerts.
108. Rule 108: Third-party providers remain replaceable through abstraction.
109. Rule 109: Every data category has a documented retention policy.
     Section T – Engineering Automation
110. Rule 110: Architecture rules are enforced by CI whenever possible.
111. Rule 111: Tenant schema requirements are automatically validated.
112. Rule 112: Every pull request follows documented governance with evidence of review.
113. Rule 113: Existing violations are corrected using this Constitution as the acceptance standard.
114. Rule 114: ShuleOS is continuously hardened throughout its lifetime.
     Section U – Offline-First Architecture
115. Rule 115: Offline-first architecture is a first-class platform capability.
116. Rule 116: Offline synchronization is fully tenant-aware and security validated.
117. Rule 117: Every synchronizable entity has a documented conflict-resolution strategy.
118. Rule 118: Users are informed of synchronization state and data freshness.
     Section V – Multi-Level Tenancy
119. Rule 119: Tenant hierarchy is explicit and governed.
120. Rule 120: Cross-school access requires an approved higher governance scope.
     Section W – Child Data Protection
121. Rule 121: Learner information receives the highest privacy classification.
122. Rule 122: Learner data is collected only when educational, administrative or legal necessity exists.
     Section X – Academic Integrity
123. Rule 123: Assessment content remains confidential until authorized publication.
124. Rule 124: Curriculum-aligned content is versioned.
     Section Y – Data Residency
125. Rule 125: Data residency decisions are documented and legally compliant.
     Section Z – Localization
126. Rule 126: Localization is a platform capability.
127. Rule 127: English and Kiswahili are first-class languages.
     Section AA – Constitutional Governance
128. Rule 128: The Constitution evolves through evidence, architecture reviews and production learning.
     Final Engineering Commitment
     No code enters ShuleOS because it works. Code enters ShuleOS because it has been proven secure, reliable, scalable, performant, tenant-safe, maintainable, privacy-aware, human-centered, operationally resilient, and compliant with this Constitution.
