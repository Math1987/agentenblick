(()=>{Shopware.Service("privileges").addPrivilegeMappingEntry({category:"permissions",parent:"marketing",key:"agentenblick",roles:{viewer:{privileges:["agentenblick.viewer"],dependencies:[]},editor:{privileges:["agentenblick.editor"],dependencies:["agentenblick.viewer"]}}});var s=`{% block agentenblick_index %}
<sw-page class="agentenblick-index">
    <template #smart-bar-header>
        <h2>{{ $t('agentenblick.general.title') }}</h2>
    </template>

    <template #smart-bar-actions>
        <mt-button :disabled="isLoading || !canView" @click="loadData">
            {{ $t('agentenblick.actions.refresh') }}
        </mt-button>
        <mt-button variant="primary" :disabled="isLoading || !canView || isExporting" :is-loading="isExporting" @click="exportCsv">
            {{ $t('agentenblick.actions.export') }}
        </mt-button>
    </template>

    <template #content>
        <sw-card-view class="agentenblick-index__content">
            <p class="agentenblick-index__intro">{{ $t('agentenblick.general.description') }}</p>

            <mt-banner v-if="!canView" variant="critical">
                {{ $t('agentenblick.errors.permission') }}
            </mt-banner>

            <template v-else>
                <div class="agentenblick-index__filters">
                    <mt-select
                        name="agentenblick-period"
                        :model-value="days"
                        :label="$t('agentenblick.filters.period')"
                        :options="periodOptions"
                        :disabled="isLoading"
                        hide-clearable-button
                        @update:model-value="onDaysChange"
                    />
                    <mt-select
                        name="agentenblick-sales-channel"
                        :model-value="salesChannelId"
                        :label="$t('agentenblick.filters.channel')"
                        :options="channelOptions"
                        :disabled="isLoading"
                        hide-clearable-button
                        @update:model-value="onChannelChange"
                    />
                </div>

                <div class="agentenblick-index__tabs" role="tablist" :aria-label="$t('agentenblick.general.title')">
                    <button
                        id="agentenblick-overview-tab"
                        ref="overviewTab"
                        type="button"
                        role="tab"
                        aria-controls="agentenblick-overview-panel"
                        :aria-selected="activeTab === 'overview'"
                        :tabindex="activeTab === 'overview' ? 0 : -1"
                        @click="selectTab('overview')"
                        @keydown.right.prevent="selectTab('agents', true)"
                        @keydown.left.prevent="selectTab('agents', true)"
                    >{{ $t('agentenblick.tabs.overview') }}</button>
                    <button
                        id="agentenblick-agents-tab"
                        ref="agentsTab"
                        type="button"
                        role="tab"
                        aria-controls="agentenblick-agents-panel"
                        :aria-selected="activeTab === 'agents'"
                        :tabindex="activeTab === 'agents' ? 0 : -1"
                        @click="selectTab('agents')"
                        @keydown.right.prevent="selectTab('overview', true)"
                        @keydown.left.prevent="selectTab('overview', true)"
                    >{{ $t('agentenblick.tabs.agents') }}</button>
                </div>

                <div v-if="isLoading" class="agentenblick-index__loading" role="status">
                    <sw-loader />
                    <span class="agentenblick-index__sr-only">{{ $t('agentenblick.general.loading') }}</span>
                </div>

                <mt-banner v-else-if="loadFailed" variant="critical">
                    <p>{{ $t('agentenblick.errors.load') }}</p>
                    <mt-button @click="loadData">{{ $t('agentenblick.actions.retry') }}</mt-button>
                </mt-banner>

                <template v-else-if="summary">
                    <mt-banner v-if="proxyWarning" class="agentenblick-index__warning" variant="attention">
                        <strong>{{ $t('agentenblick.proxy.title') }}</strong>
                        <p>{{ $t('agentenblick.proxy.description') }}</p>
                        <p>{{ $t('agentenblick.proxy.remediation') }}</p>
                    </mt-banner>

                    <section
                        v-show="activeTab === 'overview'"
                        id="agentenblick-overview-panel"
                        role="tabpanel"
                        aria-labelledby="agentenblick-overview-tab"
                    >
                        <div class="agentenblick-index__metrics">
                            <mt-card position-identifier="agentenblick-share" :title="$t('agentenblick.metrics.share')">
                                <p class="agentenblick-index__metric agentenblick-index__metric--accent">{{ formatPercent(totals.agentSessionsPct) }}</p>
                                <p class="agentenblick-index__hint">{{ $t('agentenblick.metrics.shareHint') }}</p>
                            </mt-card>
                            <mt-card position-identifier="agentenblick-agent-sessions" :title="$t('agentenblick.metrics.agentSessions')">
                                <p class="agentenblick-index__metric">{{ formatNumber(totals.agentSessions) }}</p>
                                <p class="agentenblick-index__hint">{{ $t('agentenblick.metrics.agentSessionsHint') }}</p>
                            </mt-card>
                            <mt-card position-identifier="agentenblick-agent-requests" :title="$t('agentenblick.metrics.agentRequests')">
                                <p class="agentenblick-index__metric">{{ formatNumber(totals.agentRequests) }}</p>
                                <p class="agentenblick-index__hint">{{ $t('agentenblick.metrics.requestsHint', { total: formatNumber(totals.requests) }) }}</p>
                            </mt-card>
                            <mt-card position-identifier="agentenblick-all-sessions" :title="$t('agentenblick.metrics.allSessions')">
                                <p class="agentenblick-index__metric">{{ formatNumber(totals.sessions) }}</p>
                                <p class="agentenblick-index__hint">{{ $t('agentenblick.metrics.allSessionsHint') }}</p>
                            </mt-card>
                        </div>

                        <mt-card position-identifier="agentenblick-trend" :title="$t('agentenblick.trend.title')">
                            <p v-if="!totals.requests" class="agentenblick-index__empty">{{ $t('agentenblick.general.empty') }}</p>
                            <template v-else>
                                <div class="agentenblick-index__legend">
                                    <span><i class="agentenblick-index__legend-dot agentenblick-index__legend-dot--all"></i>{{ $t('agentenblick.trend.all') }}</span>
                                    <span><i class="agentenblick-index__legend-dot agentenblick-index__legend-dot--agents"></i>{{ $t('agentenblick.trend.agents') }}</span>
                                </div>
                                <svg class="agentenblick-index__chart" viewBox="0 0 720 210" role="img" :aria-label="$t('agentenblick.trend.accessible')">
                                    <title>{{ $t('agentenblick.trend.accessible') }}</title>
                                    <g v-for="tick in chartTicks" :key="tick">
                                        <line x1="46" x2="702" :y1="chartY(tick)" :y2="chartY(tick)" class="agentenblick-index__grid-line" />
                                        <text x="38" :y="chartY(tick) + 4" text-anchor="end">{{ formatNumber(tick) }}</text>
                                    </g>
                                    <polyline :points="sessionPoints" class="agentenblick-index__line agentenblick-index__line--all" />
                                    <polyline :points="agentSessionPoints" class="agentenblick-index__line agentenblick-index__line--agents" />
                                    <circle v-for="(day, index) in daily" :key="day.date" :cx="chartX(index)" :cy="chartY(day.agentSessions)" r="3.5" class="agentenblick-index__point">
                                        <title>{{ formatDate(day.date) }}: {{ formatNumber(day.agentSessions) }} {{ $t('agentenblick.trend.agents') }} / {{ formatNumber(day.sessions) }} {{ $t('agentenblick.trend.all') }}</title>
                                    </circle>
                                    <text v-if="daily.length" x="46" y="202">{{ formatDate(daily[0].date) }}</text>
                                    <text v-if="daily.length > 1" x="702" y="202" text-anchor="end">{{ formatDate(daily[daily.length - 1].date) }}</text>
                                </svg>
                                <details class="agentenblick-index__daily-values">
                                    <summary>{{ $t('agentenblick.trend.values') }}</summary>
                                    <div class="agentenblick-index__table-wrap">
                                        <table class="agentenblick-index__table">
                                            <thead><tr><th scope="col">{{ $t('agentenblick.trend.date') }}</th><th scope="col">{{ $t('agentenblick.trend.all') }}</th><th scope="col">{{ $t('agentenblick.trend.agents') }}</th></tr></thead>
                                            <tbody><tr v-for="day in daily" :key="day.date"><th scope="row">{{ formatDate(day.date) }}</th><td>{{ formatNumber(day.sessions) }}</td><td>{{ formatNumber(day.agentSessions) }}</td></tr></tbody>
                                        </table>
                                    </div>
                                </details>
                            </template>
                            <p class="agentenblick-index__hint">{{ $t('agentenblick.general.scope') }}</p>
                        </mt-card>

                        <div class="agentenblick-index__breakdowns">
                            <mt-card position-identifier="agentenblick-kinds" :title="$t('agentenblick.breakdown.kinds')">
                                <table class="agentenblick-index__table">
                                    <thead><tr><th scope="col">{{ $t('agentenblick.table.kind') }}</th><th scope="col">{{ $t('agentenblick.table.requests') }}</th><th scope="col">{{ $t('agentenblick.table.sessions') }}</th></tr></thead>
                                    <tbody>
                                        <tr v-for="row in summary.byKind" :key="row.value"><th scope="row">{{ label('kind', row.value) }}</th><td>{{ formatNumber(row.requests) }}</td><td>{{ formatNumber(row.sessions) }}</td></tr>
                                        <tr v-if="!summary.byKind.length"><td colspan="3">{{ $t('agentenblick.general.noAgents') }}</td></tr>
                                    </tbody>
                                </table>
                            </mt-card>
                            <mt-card position-identifier="agentenblick-evidence" :title="$t('agentenblick.breakdown.evidence')">
                                <table class="agentenblick-index__table">
                                    <thead><tr><th scope="col">{{ $t('agentenblick.table.evidence') }}</th><th scope="col">{{ $t('agentenblick.table.requests') }}</th><th scope="col">{{ $t('agentenblick.table.sessions') }}</th></tr></thead>
                                    <tbody>
                                        <tr v-for="row in summary.byEvidence" :key="row.value"><th scope="row">{{ label('evidence', row.value) }}</th><td>{{ formatNumber(row.requests) }}</td><td>{{ formatNumber(row.sessions) }}</td></tr>
                                        <tr v-if="!summary.byEvidence.length"><td colspan="3">{{ $t('agentenblick.general.noAgents') }}</td></tr>
                                    </tbody>
                                </table>
                                <p class="agentenblick-index__hint">{{ $t('agentenblick.breakdown.proofHint') }}</p>
                            </mt-card>
                        </div>
                    </section>

                    <section
                        v-show="activeTab === 'agents'"
                        id="agentenblick-agents-panel"
                        role="tabpanel"
                        aria-labelledby="agentenblick-agents-tab"
                    >
                        <mt-card position-identifier="agentenblick-agents" :title="$t('agentenblick.tabs.agents')">
                            <p class="agentenblick-index__hint">{{ $t('agentenblick.table.proofHint') }}</p>
                            <div class="agentenblick-index__table-wrap">
                                <table class="agentenblick-index__table agentenblick-index__agents-table">
                                    <caption class="agentenblick-index__sr-only">{{ $t('agentenblick.table.caption') }}</caption>
                                    <thead><tr>
                                        <th scope="col">{{ $t('agentenblick.table.agent') }}</th>
                                        <th scope="col">{{ $t('agentenblick.table.evidence') }}</th>
                                        <th scope="col">{{ $t('agentenblick.table.requests') }}</th>
                                        <th scope="col">{{ $t('agentenblick.table.sessions') }}</th>
                                        <th scope="col">{{ $t('agentenblick.table.lastSeen') }}</th>
                                        <th scope="col">{{ $t('agentenblick.table.routes') }}</th>
                                    </tr></thead>
                                    <tbody>
                                        <tr v-for="agent in agents" :key="\`\${agent.operator}:\${agent.agent}\`">
                                            <th scope="row"><strong>{{ agent.agent }}</strong><span class="agentenblick-index__subline">{{ agent.operator }} \xB7 {{ label('kind', agent.kind) }}</span></th>
                                            <td>
                                                <span class="agentenblick-proof" :class="evidenceClass(agent.evidence)">{{ label('evidence', agent.evidence) }}</span>
                                                <ul v-if="agent.evidence === 'mixed'" class="agentenblick-index__proof-list">
                                                    <li v-for="proof in agent.byEvidence" :key="proof.value">{{ label('evidence', proof.value) }}: {{ formatNumber(proof.requests) }}</li>
                                                </ul>
                                            </td>
                                            <td>{{ formatNumber(agent.requests) }}</td>
                                            <td>{{ formatNumber(agent.sessions) }}</td>
                                            <td class="agentenblick-index__date">{{ formatDate(agent.lastSeen, true) }}</td>
                                            <td>
                                                <details v-if="agent.topRoutes && agent.topRoutes.length">
                                                    <summary>{{ $t('agentenblick.table.showRoutes') }}</summary>
                                                    <ul class="agentenblick-index__route-list"><li v-for="route in agent.topRoutes" :key="route.route"><code>{{ route.route }}</code><span>{{ formatNumber(route.requests) }}</span></li></ul>
                                                </details>
                                                <span v-else>\u2014</span>
                                            </td>
                                        </tr>
                                        <tr v-if="!agents.length"><td colspan="6" class="agentenblick-index__empty">{{ $t('agentenblick.general.noAgents') }}</td></tr>
                                    </tbody>
                                </table>
                            </div>
                            <div v-if="totalAgents" class="agentenblick-index__pagination">
                                <span>{{ $t('agentenblick.table.pagination', { page, pages: pageCount, total: formatNumber(totalAgents) }) }}</span>
                                <mt-button size="small" :disabled="page <= 1" @click="changePage(-1)">{{ $t('agentenblick.actions.previous') }}</mt-button>
                                <mt-button size="small" :disabled="page >= pageCount" @click="changePage(1)">{{ $t('agentenblick.actions.next') }}</mt-button>
                            </div>
                            <p class="agentenblick-index__hint">{{ $t('agentenblick.table.retentionHint') }}</p>
                        </mt-card>
                    </section>

                    <mt-card v-if="pendingRanges.length" position-identifier="agentenblick-pending-ranges" :title="$t('agentenblick.ranges.title')">
                        <p class="agentenblick-index__hint">{{ $t('agentenblick.ranges.description') }}</p>
                        <div class="agentenblick-index__table-wrap">
                            <table class="agentenblick-index__table">
                                <thead><tr><th scope="col">{{ $t('agentenblick.table.agent') }}</th><th scope="col">{{ $t('agentenblick.ranges.current') }}</th><th scope="col">{{ $t('agentenblick.ranges.proposed') }}</th><th scope="col">{{ $t('agentenblick.ranges.updated') }}</th><th scope="col"><span class="agentenblick-index__sr-only">{{ $t('agentenblick.ranges.review') }}</span></th></tr></thead>
                                <tbody><tr v-for="range in pendingRanges" :key="range.agent">
                                    <th scope="row">{{ range.agent }}</th><td>{{ formatNumber(range.currentCount) }}</td><td>{{ formatNumber(range.pendingCount) }}</td><td>{{ formatDate(range.updatedAt, true) }}</td>
                                    <td><mt-button v-if="canEdit" size="small" @click="selectedRange = range">{{ $t('agentenblick.ranges.review') }}</mt-button><span v-else class="agentenblick-index__hint">{{ $t('agentenblick.ranges.editorRequired') }}</span></td>
                                </tr></tbody>
                            </table>
                        </div>
                    </mt-card>
                </template>
            </template>
        </sw-card-view>

        <sw-modal v-if="selectedRange && canEdit" :title="$t('agentenblick.ranges.confirmTitle', { agent: selectedRange.agent })" @modal-close="closeRange">
            <p>{{ $t('agentenblick.ranges.confirmText', { current: formatNumber(selectedRange.currentCount), proposed: formatNumber(selectedRange.pendingCount) }) }}</p>
            <p v-if="publicSourceUrl(selectedRange.sourceUrl)" class="agentenblick-index__range-source"><a :href="publicSourceUrl(selectedRange.sourceUrl)" target="_blank" rel="noopener noreferrer">{{ $t('agentenblick.ranges.source') }}</a></p>
            <div class="agentenblick-index__range-diff">
                <div><h4>{{ $t('agentenblick.ranges.added', { count: formatNumber(selectedRange.addedCount) }) }}</h4><ul><li v-for="range in selectedRange.addedRanges" :key="range"><code>{{ range }}</code></li></ul></div>
                <div><h4>{{ $t('agentenblick.ranges.removed', { count: formatNumber(selectedRange.removedCount) }) }}</h4><ul><li v-for="range in selectedRange.removedRanges" :key="range"><code>{{ range }}</code></li></ul></div>
            </div>
            <p v-if="selectedRange.changesTruncated" class="agentenblick-index__hint">{{ $t('agentenblick.ranges.truncated') }}</p>
            <template #modal-footer>
                <mt-button :disabled="isApproving" @click="closeRange">{{ $t('agentenblick.actions.cancel') }}</mt-button>
                <mt-button variant="primary" :is-loading="isApproving" :disabled="isApproving" @click="approveRange">{{ $t('agentenblick.ranges.approve') }}</mt-button>
            </template>
        </sw-modal>
    </template>
</sw-page>
{% endblock %}
`;var{Component:d,Mixin:g}=Shopware;d.register("agentenblick-index",{template:s,inject:["agentenblickApiService","acl"],mixins:[g.getByName("notification")],data(){return{days:7,salesChannelId:"",activeTab:"overview",page:1,limit:25,summary:null,agents:[],totalAgents:0,pendingRanges:[],selectedRange:null,isLoading:!1,loadFailed:!1,isExporting:!1,isApproving:!1,requestSequence:0}},computed:{canView(){return this.acl.can("agentenblick.viewer")},canEdit(){return this.acl.can("agentenblick.editor")},locale(){let e=this.$i18n.locale;return(typeof e=="string"?e:e?.value)||"en-GB"},periodOptions(){return[7,30].map(e=>({value:e,label:this.$t("agentenblick.filters.lastDays",{days:e})}))},channelOptions(){return[{value:"",label:this.$t("agentenblick.filters.allChannels")},...(this.summary?.salesChannels||[]).map(e=>({value:e.id,label:e.name||e.id}))]},filters(){return{days:this.days,...this.salesChannelId?{salesChannelId:this.salesChannelId}:{}}},totals(){return this.summary?.totals||{}},daily(){return this.summary?.daily||[]},proxyWarning(){return this.summary?.proxyDiagnostics?.warning===!0},pageCount(){return Math.max(1,Math.ceil(this.totalAgents/this.limit))},chartMaximum(){return Math.max(1,...this.daily.map(e=>Number(e.sessions)||0))},chartTicks(){return[...new Set([0,Math.ceil(this.chartMaximum/2),this.chartMaximum])]},sessionPoints(){return this.chartPoints("sessions")},agentSessionPoints(){return this.chartPoints("agentSessions")}},created(){this.loadData()},beforeUnmount(){this.requestSequence+=1},methods:{async loadData(){if(!this.canView)return;let e=++this.requestSequence;this.isLoading=!0,this.loadFailed=!1;try{let[t,n,a]=await Promise.all([this.agentenblickApiService.getSummary(this.filters),this.agentenblickApiService.getAgents({...this.filters,page:this.page,limit:this.limit}),this.agentenblickApiService.getPendingRanges()]);if(e!==this.requestSequence)return;this.summary=t,this.agents=n.items||[],this.totalAgents=Number(n.total)||0,this.pendingRanges=a.items||[]}catch{e===this.requestSequence&&(this.loadFailed=!0)}finally{e===this.requestSequence&&(this.isLoading=!1)}},onDaysChange(e){let t=Number(e);![7,30].includes(t)||t===this.days||(this.days=t,this.page=1,this.loadData())},onChannelChange(e){(e||"")!==this.salesChannelId&&(this.salesChannelId=e||"",this.page=1,this.loadData())},changePage(e){let t=this.page+e;t<1||t>this.pageCount||(this.page=t,this.loadData())},selectTab(e,t=!1){this.activeTab=e,t&&this.$nextTick(()=>this.$refs[`${e}Tab`]?.focus())},formatNumber(e){return new Intl.NumberFormat(this.locale).format(Number(e)||0)},formatPercent(e){return e==null?"\u2014":new Intl.NumberFormat(this.locale,{style:"percent",maximumFractionDigits:1}).format(Number(e)/100)},formatDate(e,t=!1){if(!e)return"\u2014";let n=String(e).replace(" ","T"),a=new Date(n.length===10?`${n}T00:00:00Z`:/(?:Z|[+-]\d{2}:?\d{2})$/.test(n)?n:`${n}Z`);return Number.isNaN(a.getTime())?"\u2014":new Intl.DateTimeFormat(this.locale,{timeZone:"UTC",day:"2-digit",month:"short",...t?{hour:"2-digit",minute:"2-digit"}:{}}).format(a)},label(e,t){let n=`agentenblick.${e}.${t}`;return this.$te(n)?this.$t(n):t},chartX(e){return 46+(this.daily.length>1?e/(this.daily.length-1)*656:328)},chartY(e){return 174-(Number(e)||0)/this.chartMaximum*144},chartPoints(e){return this.daily.map((t,n)=>`${this.chartX(n)},${this.chartY(t[e])}`).join(" ")},evidenceClass(e){return["verified_signature","verified_ip","verified_rdns"].includes(e)?"agentenblick-proof--verified":"agentenblick-proof--unverified"},async exportCsv(){if(!(this.isExporting||!this.canView)){this.isExporting=!0;try{let e=await this.agentenblickApiService.exportAgents(this.filters),t=URL.createObjectURL(e),n=document.createElement("a");n.href=t,n.download=`agentenblick-agents-${this.days}d.csv`,document.body.appendChild(n),n.click(),n.remove(),setTimeout(()=>URL.revokeObjectURL(t),1e3)}catch{this.createNotificationError({message:this.$t("agentenblick.errors.export")})}finally{this.isExporting=!1}}},closeRange(){this.isApproving||(this.selectedRange=null)},async approveRange(){if(!(!this.canEdit||this.isApproving||!this.selectedRange)){this.isApproving=!0;try{await this.agentenblickApiService.approveRanges(this.selectedRange.agent,this.selectedRange.version),this.selectedRange=null,this.createNotificationSuccess({message:this.$t("agentenblick.ranges.approved")}),await this.loadData()}catch(e){let t=e.response?.status===409;this.createNotificationError({message:this.$t(t?"agentenblick.ranges.changed":"agentenblick.errors.approve")}),t&&(this.selectedRange=null,await this.loadData())}finally{this.isApproving=!1}}},publicSourceUrl(e){try{let t=new URL(e);return t.protocol==="https:"&&!t.username&&!t.password?t.href:null}catch{return null}}}});var{ApiService:r}=Shopware.Classes,i=class extends r{constructor(t,n){super(t,n,"_action/agentenblick","application/json")}getSummary(t){return this.get("summary",t)}getAgents(t){return this.get("agents",t)}getPendingRanges(){return this.get("ranges/pending")}get(t,n={}){return this.httpClient.get(`${this.getApiBasePath()}/${t}`,{headers:this.getBasicHeaders(),params:n}).then(r.handleResponse)}approveRanges(t,n){return this.httpClient.post(`${this.getApiBasePath()}/ranges/${encodeURIComponent(t)}/approve`,{version:n},{headers:this.getBasicHeaders()}).then(r.handleResponse)}exportAgents(t){return this.httpClient.get(`${this.getApiBasePath()}/export`,{headers:this.getBasicHeaders({Accept:"text/csv"}),params:t,responseType:"blob"}).then(n=>n.data)}};var l={agentenblick:{general:{title:"Agentenblick",menu:"AI agents",description:"See the crawlers and agents visiting your shop, with the evidence behind each identity.",loading:"Loading observed traffic\u2026",empty:"No requests observed for this period and sales channel yet.",noAgents:"No crawler or agent traffic observed for this selection.",scope:"Only requests reaching Shopware are observed. Ordinary-looking browser agents may remain unidentified. Sessions are provisional; a shared agent key can group several visits. All dates use UTC."},actions:{refresh:"Refresh",export:"Export agents CSV",retry:"Try again",previous:"Previous",next:"Next",cancel:"Cancel"},tabs:{overview:"Overview",agents:"Agents"},filters:{period:"Period",lastDays:"Last {days} days",channel:"Sales channel",allChannels:"All sales channels"},metrics:{share:"Observed agent share",shareHint:"Share of all observed sessions, including declared identities.",agentSessions:"Agent sessions",agentSessionsHint:"Crawlers and agents, verified or declared.",agentRequests:"Agent requests",requestsHint:"Out of {total} observed requests.",allSessions:"All sessions",allSessionsHint:"Humans, unidentified traffic and agents."},trend:{title:"Sessions over time",all:"All sessions",agents:"Agent sessions",accessible:"Daily total and agent sessions. Exact values are available in the table below.",values:"Show daily values",date:"Date (UTC)"},breakdown:{kinds:"Types of agent",evidence:"Identity evidence",proofHint:"Requests retain their own evidence. Sessions use the strongest proof observed during collection; deferred verification applies stricter rules."},table:{caption:"Observed agents and their identity evidence",agent:"Agent / operator",kind:"Type",evidence:"Evidence",requests:"Requests",sessions:"Sessions",lastSeen:"Last seen (UTC)",routes:"Top routes",showRoutes:"Show routes",pagination:"Page {page} of {pages} \xB7 {total} agents",proofHint:"A declared identity is not verified and is not proof of impersonation. Mixed evidence is shown separately, with request counts.",retentionHint:"Last seen and top routes use retained request details. A generic route means Shopware did not expose a resolved route, for example on a cache hit."},kind:{training_crawler:"Training crawler",search_crawler:"Search crawler",user_fetch:"User-triggered fetch",browsing_agent:"Browsing agent",automation_unknown:"Unidentified automation",human_or_unknown:"Human or unknown",mixed:"Multiple types"},evidence:{verified_signature:"Signature verified",verified_ip:"IP range verified",verified_rdns:"Reverse DNS verified",declared_unverified:"Declared, unverified",none:"No identity evidence",mixed:"Mixed evidence"},proxy:{title:"Check your trusted proxy configuration",description:"Most resolved client IPs in the current observation window belong to known CDN ranges. IP verification may be using the CDN address instead of the visitor address. This is not proof of impersonation.",remediation:"Configure only your actual proxy addresses in TRUSTED_PROXIES and the forwarded headers trusted by Symfony. The proxy must overwrite visitor-supplied headers. Verify the result of Request::getClientIp(). The warning clears when a new hourly window no longer has a CDN majority."},ranges:{title:"IP range updates awaiting review",description:"These official lists changed by more than 30%. The existing ranges remain active until an editor approves the proposed version.",current:"Current ranges",proposed:"Proposed ranges",updated:"Downloaded (UTC)",review:"Review change",editorRequired:"Editor permission required",confirmTitle:"Approve IP ranges for {agent}",confirmText:"Replace the current {current} ranges with the proposed {proposed} ranges? Review the differences and the operator's official source before applying this version.",source:"Open official source",added:"Added ({count})",removed:"Removed ({count})",truncated:"The first 50 added and removed ranges are shown. Check the official source for the complete list.",approve:"Approve this version",approved:"The reviewed IP range version is now active.",changed:"The proposed ranges changed while you were reviewing them. Review the refreshed version before approving."},errors:{load:"Traffic could not be loaded for this selection. Please try again.",export:"The CSV could not be downloaded. Try again or check your viewing permission.",approve:"The IP ranges could not be approved. Check your editor permission and try again.",permission:"You do not have permission to view Agentenblick."}},"sw-privileges":{permissions:{agentenblick:{label:"Agentenblick"}}}};var o={agentenblick:{general:{title:"Agentenblick",menu:"KI-Agenten",description:"Erkennen Sie, welche Crawler und Agenten Ihren Shop besuchen und welche Nachweise ihre Identit\xE4t best\xE4tigen.",loading:"Beobachteten Traffic laden\u2026",empty:"F\xFCr diesen Zeitraum und Verkaufskanal wurden noch keine Anfragen beobachtet.",noAgents:"F\xFCr diese Auswahl wurde kein Crawler- oder Agenten-Traffic beobachtet.",scope:"Erfasst werden nur Anfragen, die Shopware erreichen. Agenten mit gew\xF6hnlicher Browserkennung k\xF6nnen unerkannt bleiben. Sitzungen sind vorl\xE4ufig; ein gemeinsamer Agentenschl\xFCssel kann mehrere Besuche zusammenfassen. Alle Zeitangaben sind in UTC."},actions:{refresh:"Aktualisieren",export:"Agenten als CSV exportieren",retry:"Erneut versuchen",previous:"Zur\xFCck",next:"Weiter",cancel:"Abbrechen"},tabs:{overview:"\xDCbersicht",agents:"Agenten"},filters:{period:"Zeitraum",lastDays:"Letzte {days} Tage",channel:"Verkaufskanal",allChannels:"Alle Verkaufskan\xE4le"},metrics:{share:"Beobachteter Agentenanteil",shareHint:"Anteil an allen beobachteten Sitzungen, einschlie\xDFlich deklarierter Identit\xE4ten.",agentSessions:"Agentensitzungen",agentSessionsHint:"Crawler und Agenten, verifiziert oder deklariert.",agentRequests:"Agentenanfragen",requestsHint:"Von insgesamt {total} beobachteten Anfragen.",allSessions:"Alle Sitzungen",allSessionsHint:"Menschen, unbekannter Traffic und Agenten."},trend:{title:"Sitzungen im Zeitverlauf",all:"Alle Sitzungen",agents:"Agentensitzungen",accessible:"T\xE4gliche Anzahl aller Sitzungen und Agentensitzungen. Die genauen Werte stehen in der Tabelle darunter.",values:"Tageswerte anzeigen",date:"Datum (UTC)"},breakdown:{kinds:"Agententypen",evidence:"Identit\xE4tsnachweise",proofHint:"Jede Anfrage beh\xE4lt ihren eigenen Nachweis. Sitzungen verwenden den st\xE4rksten bei der Erfassung beobachteten Nachweis; f\xFCr sp\xE4tere Pr\xFCfungen gelten strengere Regeln."},table:{caption:"Beobachtete Agenten und ihre Identit\xE4tsnachweise",agent:"Agent / Betreiber",kind:"Typ",evidence:"Nachweis",requests:"Anfragen",sessions:"Sitzungen",lastSeen:"Zuletzt gesehen (UTC)",routes:"H\xE4ufigste Routen",showRoutes:"Routen anzeigen",pagination:"Seite {page} von {pages} \xB7 {total} Agenten",proofHint:"Eine deklarierte Identit\xE4t ist nicht verifiziert und belegt keine Identit\xE4tst\xE4uschung. Unterschiedliche Nachweise werden mit ihrer jeweiligen Anfrageanzahl angezeigt.",retentionHint:"Letzter Besuch und h\xE4ufigste Routen stammen aus den noch gespeicherten Anfragedetails. Eine generische Route bedeutet, dass Shopware keine aufgel\xF6ste Route bereitgestellt hat, etwa bei einem Cache-Treffer."},kind:{training_crawler:"Trainings-Crawler",search_crawler:"Suchmaschinen-Crawler",user_fetch:"Nutzerinitiierter Abruf",browsing_agent:"Browser-Agent",automation_unknown:"Unbekannte Automatisierung",human_or_unknown:"Mensch oder unbekannt",mixed:"Mehrere Typen"},evidence:{verified_signature:"Signatur verifiziert",verified_ip:"IP-Bereich verifiziert",verified_rdns:"Reverse-DNS verifiziert",declared_unverified:"Deklariert, nicht verifiziert",none:"Kein Identit\xE4tsnachweis",mixed:"Unterschiedliche Nachweise"},proxy:{title:"Konfiguration vertrauensw\xFCrdiger Proxys pr\xFCfen",description:"Die meisten aufgel\xF6sten Client-IP-Adressen im aktuellen Beobachtungsfenster geh\xF6ren zu bekannten CDN-Bereichen. Die IP-Pr\xFCfung verwendet m\xF6glicherweise die CDN-Adresse statt der Besucheradresse. Dies belegt keine Identit\xE4tst\xE4uschung.",remediation:"Tragen Sie in TRUSTED_PROXIES nur Ihre tats\xE4chlichen Proxy-Adressen ein und konfigurieren Sie die von Symfony akzeptierten Weiterleitungsheader. Der Proxy muss vom Besucher gesendete Header \xFCberschreiben. Pr\xFCfen Sie das Ergebnis von Request::getClientIp(). Die Warnung verschwindet in einem neuen Stundenfenster ohne CDN-Mehrheit."},ranges:{title:"IP-Bereichs\xE4nderungen zur Pr\xFCfung",description:"Diese offiziellen Listen haben sich um mehr als 30 % ge\xE4ndert. Die bisherigen Bereiche bleiben aktiv, bis ein Bearbeiter die vorgeschlagene Version best\xE4tigt.",current:"Aktuelle Bereiche",proposed:"Vorgeschlagene Bereiche",updated:"Heruntergeladen (UTC)",review:"\xC4nderung pr\xFCfen",editorRequired:"Bearbeitungsrecht erforderlich",confirmTitle:"IP-Bereiche f\xFCr {agent} best\xE4tigen",confirmText:"Die bisherigen {current} Bereiche durch die vorgeschlagenen {proposed} Bereiche ersetzen? Pr\xFCfen Sie vor der \xDCbernahme die Unterschiede und die offizielle Quelle des Betreibers.",source:"Offizielle Quelle \xF6ffnen",added:"Hinzugef\xFCgt ({count})",removed:"Entfernt ({count})",truncated:"Es werden jeweils die ersten 50 hinzugef\xFCgten und entfernten Bereiche angezeigt. Die vollst\xE4ndige Liste finden Sie in der offiziellen Quelle.",approve:"Diese Version best\xE4tigen",approved:"Die gepr\xFCfte Version der IP-Bereiche ist jetzt aktiv.",changed:"Die vorgeschlagenen Bereiche wurden w\xE4hrend Ihrer Pr\xFCfung ge\xE4ndert. Pr\xFCfen Sie die aktualisierte Version vor der Best\xE4tigung."},errors:{load:"Der Traffic f\xFCr diese Auswahl konnte nicht geladen werden. Bitte versuchen Sie es erneut.",export:"Die CSV-Datei konnte nicht heruntergeladen werden. Versuchen Sie es erneut oder pr\xFCfen Sie Ihr Leserecht.",approve:"Die IP-Bereiche konnten nicht best\xE4tigt werden. Pr\xFCfen Sie Ihr Bearbeitungsrecht und versuchen Sie es erneut.",permission:"Sie haben keine Berechtigung, Agentenblick anzuzeigen."}},"sw-privileges":{permissions:{agentenblick:{label:"Agentenblick"}}}};Shopware.Service().register("agentenblickApiService",()=>new i(Shopware.Application.getContainer("init").httpClient,Shopware.Service("loginService")));Shopware.Module.register("aithos-agentenblick",{type:"plugin",routePrefixName:"agentenblick",routePrefixPath:"agentenblick",name:"Agentenblick",title:"agentenblick.general.title",description:"agentenblick.general.description",color:"#147d75",icon:"regular-chart-pie",snippets:{"en-GB":l,"de-DE":o},routes:{index:{component:"agentenblick-index",path:"index",meta:{privilege:"agentenblick.viewer"}}},navigation:[{id:"agentenblick",parent:"sw-marketing",path:"agentenblick.index",label:"agentenblick.general.menu",color:"#147d75",icon:"regular-chart-pie",position:70,privilege:"agentenblick.viewer"}]});})();
