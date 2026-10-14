# Privacy notice template — English

Complete the bracketed fields and have the notice checked for the actual shop before publication. This template describes the implementation, not a legal determination that the processing is anonymous or that a particular legal basis applies. The [EDPB guide](https://www.edpb.europa.eu/sme/be-compliant/process-personal-data-lawfully_en) explains why the controller must identify an appropriate legal basis.

**Controller and contact:** [legal entity, address, privacy contact and DPO where applicable].

We use Agentenblick to understand automated requests reaching our Shopware server, check the evidence for declared agent identities and identify technical problems in shopping journeys. Agentenblick adds no visitor JavaScript or cookie. Ordinary human or unidentified traffic contributes aggregate counters and temporary pseudonymous session records; no detailed human request history is retained.

Identifiers are derived locally using a secret that changes daily. Existing Shopware context/session identifiers, or request characteristics, can be used in memory to associate requests provisionally. Raw visitor IPs, cookie values, context tokens, request bodies, query strings and Referer values are not stored by Agentenblick. For classified automated traffic it retains bounded user-agent information, route names or generic path templates, timestamps, HTTP status, evidence, observed steps and evaluated failures. An actual order identifier may link an automated session to a Shopware order; payment success is not inferred. Signature material is retained only when compatible with these minimization rules.

**Configured retention:** [enter the actual settings]. Defaults are 48 hours for human/unknown session records, 90 days for automated request/session detail, and 13 months for local aggregates. Purging requires the Shopware scheduled tasks to run. Separate shop, proxy, CDN and server logs follow their own policies.

**Legal basis and purpose assessment:** [specify the applicable basis, and the legitimate interests/balancing assessment if relied upon]. **Recipients and infrastructure:** [hosting and other actual recipients, locations and transfer safeguards]. Identity verification may contact public operator key directories or DNS services; the offline setting disables outbound verification.

**Optional benchmark — include only if enabled:** After an explicit merchant action, weekly rounded aggregate shares and broad shop characteristics are sent to Aithos at shopware.aithos.app. A random installation and channel identifier permits retries and withdrawal. No visitor identity, IP, domain, URL, cookie, user-agent, order, cart or exact traffic count is sent. Values below ten eligible local sessions are suppressed; published comparisons require five distinct eligible installations per metric. Contributions expire after 13 months. Thresholds reduce disclosure risk; they are not a blanket legal guarantee of anonymity. Withdrawing stops new submissions immediately; remote deletion may remain pending during an outage. Published annual aggregates contain no installation identifiers and may remain.

Experimental inference of undeclared automation is disabled for the demo. If enabled later, update this notice: it uses bounded local request signals, may misclassify people and is not proof of AI use.

**Your rights and contact:** [describe applicable access, rectification, erasure, restriction, objection, portability and complaint rights, withdrawal where consent applies, and contact/supervisory authority]. The [EDPB rights guide](https://www.edpb.europa.eu/sme/be-compliant/respect-individuals-rights_en) helps adapt this section. No automated decision restricting a purchase is made by Agentenblick.
