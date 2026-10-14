(()=>{Shopware.Service("privileges").addPrivilegeMappingEntry({category:"permissions",parent:"marketing",key:"agentenblick",roles:{viewer:{privileges:["agentenblick.viewer"],dependencies:[]},editor:{privileges:["agentenblick.editor"],dependencies:["agentenblick.viewer"]}}});var o=`{% block agentenblick_index %}
<sw-page class="agentenblick-index">
    <template #smart-bar-header>
        <h2>{{ $t('agentenblick.general.title') }}</h2>
    </template>

    <template #smart-bar-actions>
        <mt-button v-if="activeTab !== 'benchmark'" :disabled="isLoading || !canView" @click="refreshVisible">
            {{ $t('agentenblick.actions.refresh') }}
        </mt-button>
        <mt-button v-if="['overview', 'agents'].includes(activeTab)" variant="primary" :disabled="isLoading || !canView || isExporting" :is-loading="isExporting" @click="exportCsv">
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
                <div v-if="activeTab !== 'benchmark'" class="agentenblick-index__filters">
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
                        v-for="tab in tabs"
                        :id="\`agentenblick-\${tab}-tab\`"
                        :key="tab"
                        :ref="\`\${tab}Tab\`"
                        type="button"
                        role="tab"
                        :aria-controls="\`agentenblick-\${tab}-panel\`"
                        :aria-selected="activeTab === tab"
                        :tabindex="activeTab === tab ? 0 : -1"
                        @click="selectTab(tab)"
                        @keydown.right.prevent="moveTab(1)"
                        @keydown.left.prevent="moveTab(-1)"
                        @keydown.home.prevent="selectTab(tabs[0], true)"
                        @keydown.end.prevent="selectTab(tabs[tabs.length - 1], true)"
                    >{{ $t(\`agentenblick.tabs.\${tab}\`) }}</button>
                </div>

                <section v-if="activeTab === 'benchmark'" id="agentenblick-benchmark-panel" role="tabpanel" aria-labelledby="agentenblick-benchmark-tab">
                    <agentenblick-benchmark />
                </section>

                <section v-else-if="isJourneyTab" :id="\`agentenblick-\${activeTab}-panel\`" role="tabpanel" :aria-labelledby="\`agentenblick-\${activeTab}-tab\`">
                    <agentenblick-journey ref="journey" :mode="activeTab" :filters="filters" />
                </section>

                <div v-else-if="isLoading" class="agentenblick-index__loading" role="status">
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
`;var{Component:u,Mixin:b}=Shopware;u.register("agentenblick-index",{template:o,inject:["agentenblickApiService","acl"],mixins:[b.getByName("notification")],data(){return{days:7,salesChannelId:"",activeTab:"overview",page:1,limit:25,summary:null,agents:[],totalAgents:0,pendingRanges:[],selectedRange:null,isLoading:!1,loadFailed:!1,isExporting:!1,isApproving:!1,requestSequence:0}},computed:{tabs(){return["overview","agents","funnel","journeys","failures","benchmark"]},isJourneyTab(){return["funnel","journeys","failures"].includes(this.activeTab)},canView(){return this.acl.can("agentenblick.viewer")},canEdit(){return this.acl.can("agentenblick.editor")},locale(){let e=this.$i18n.locale;return(typeof e=="string"?e:e?.value)||"en-GB"},periodOptions(){return[7,30].map(e=>({value:e,label:this.$t("agentenblick.filters.lastDays",{days:e})}))},channelOptions(){return[{value:"",label:this.$t("agentenblick.filters.allChannels")},...(this.summary?.salesChannels||[]).map(e=>({value:e.id,label:e.name||e.id}))]},filters(){return{days:this.days,...this.salesChannelId?{salesChannelId:this.salesChannelId}:{}}},totals(){return this.summary?.totals||{}},daily(){return this.summary?.daily||[]},proxyWarning(){return this.summary?.proxyDiagnostics?.warning===!0},pageCount(){return Math.max(1,Math.ceil(this.totalAgents/this.limit))},chartMaximum(){return Math.max(1,...this.daily.map(e=>Number(e.sessions)||0))},chartTicks(){return[...new Set([0,Math.ceil(this.chartMaximum/2),this.chartMaximum])]},sessionPoints(){return this.chartPoints("sessions")},agentSessionPoints(){return this.chartPoints("agentSessions")}},created(){this.loadData()},beforeUnmount(){this.requestSequence+=1},methods:{async loadData(){if(!this.canView)return;let e=++this.requestSequence;this.isLoading=!0,this.loadFailed=!1;try{let[n,t,i]=await Promise.all([this.agentenblickApiService.getSummary(this.filters),this.agentenblickApiService.getAgents({...this.filters,page:this.page,limit:this.limit}),this.agentenblickApiService.getPendingRanges()]);if(e!==this.requestSequence)return;this.summary=n,this.agents=t.items||[],this.totalAgents=Number(t.total)||0,this.pendingRanges=i.items||[]}catch{e===this.requestSequence&&(this.loadFailed=!0)}finally{e===this.requestSequence&&(this.isLoading=!1)}},onDaysChange(e){let n=Number(e);![7,30].includes(n)||n===this.days||(this.days=n,this.page=1,this.loadData())},onChannelChange(e){(e||"")!==this.salesChannelId&&(this.salesChannelId=e||"",this.page=1,this.loadData())},changePage(e){let n=this.page+e;n<1||n>this.pageCount||(this.page=n,this.loadData())},selectTab(e,n=!1){this.tabs.includes(e)&&(this.activeTab=e,n&&this.$nextTick(()=>{let t=this.$refs[`${e}Tab`];(Array.isArray(t)?t[0]:t)?.focus()}))},moveTab(e){this.selectTab(this.tabs[(this.tabs.indexOf(this.activeTab)+e+this.tabs.length)%this.tabs.length],!0)},refreshVisible(){this.isJourneyTab?this.$refs.journey?.load():this.loadData()},formatNumber(e){return new Intl.NumberFormat(this.locale).format(Number(e)||0)},formatPercent(e){return e==null?"\u2014":new Intl.NumberFormat(this.locale,{style:"percent",maximumFractionDigits:1}).format(Number(e)/100)},formatDate(e,n=!1){if(!e)return"\u2014";let t=String(e).replace(" ","T"),i=new Date(t.length===10?`${t}T00:00:00Z`:/(?:Z|[+-]\d{2}:?\d{2})$/.test(t)?t:`${t}Z`);return Number.isNaN(i.getTime())?"\u2014":new Intl.DateTimeFormat(this.locale,{timeZone:"UTC",day:"2-digit",month:"short",...n?{hour:"2-digit",minute:"2-digit"}:{}}).format(i)},label(e,n){let t=`agentenblick.${e}.${n}`;return this.$te(t)?this.$t(t):n},chartX(e){return 46+(this.daily.length>1?e/(this.daily.length-1)*656:328)},chartY(e){return 174-(Number(e)||0)/this.chartMaximum*144},chartPoints(e){return this.daily.map((n,t)=>`${this.chartX(t)},${this.chartY(n[e])}`).join(" ")},evidenceClass(e){return["verified_signature","verified_ip","verified_rdns"].includes(e)?"agentenblick-proof--verified":"agentenblick-proof--unverified"},async exportCsv(){if(!(this.isExporting||!this.canView)){this.isExporting=!0;try{let e=await this.agentenblickApiService.exportAgents(this.filters),n=URL.createObjectURL(e),t=document.createElement("a");t.href=n,t.download=`agentenblick-agents-${this.days}d.csv`,document.body.appendChild(t),t.click(),t.remove(),setTimeout(()=>URL.revokeObjectURL(n),1e3)}catch{this.createNotificationError({message:this.$t("agentenblick.errors.export")})}finally{this.isExporting=!1}}},closeRange(){this.isApproving||(this.selectedRange=null)},async approveRange(){if(!(!this.canEdit||this.isApproving||!this.selectedRange)){this.isApproving=!0;try{await this.agentenblickApiService.approveRanges(this.selectedRange.agent,this.selectedRange.version),this.selectedRange=null,this.createNotificationSuccess({message:this.$t("agentenblick.ranges.approved")}),await this.loadData()}catch(e){let n=e.response?.status===409;this.createNotificationError({message:this.$t(n?"agentenblick.ranges.changed":"agentenblick.errors.approve")}),n&&(this.selectedRange=null,await this.loadData())}finally{this.isApproving=!1}}},publicSourceUrl(e){try{let n=new URL(e);return n.protocol==="https:"&&!n.username&&!n.password?n.href:null}catch{return null}}}});var l=`<div class="agentenblick-benchmark">
    <div v-if="loading" class="agentenblick-index__loading" role="status">
        <sw-loader />
        <span class="agentenblick-index__sr-only">{{ $t('agentenblick.benchmark.loading') }}</span>
    </div>
    <mt-banner v-else-if="loadFailed" variant="critical">
        <p>{{ $t('agentenblick.benchmark.error.load') }}</p>
        <mt-button @click="load">{{ $t('agentenblick.actions.retry') }}</mt-button>
    </mt-banner>
    <template v-else-if="status">
        <mt-banner v-if="identityConflict" variant="critical">
            {{ $t('agentenblick.benchmark.identityConflict') }}
        </mt-banner>
        <mt-banner v-if="status.demo" variant="attention">
            {{ $t('agentenblick.benchmark.demo') }}
        </mt-banner>
        <mt-banner v-if="status.offline" variant="attention">
            {{ $t('agentenblick.benchmark.offline') }}
        </mt-banner>
        <mt-banner v-if="status.pendingWithdrawal" variant="attention">
            {{ $t('agentenblick.benchmark.withdrawalPending') }}
        </mt-banner>
        <mt-banner v-if="needsConsentUpdate" variant="attention">{{ $t('agentenblick.benchmark.consentUpdate') }}</mt-banner>

        <mt-card position-identifier="agentenblick-benchmark-participation" :title="$t('agentenblick.benchmark.title')">
            <div class="agentenblick-benchmark__heading">
                <p>{{ $t('agentenblick.benchmark.description') }}</p>
                <span class="agentenblick-benchmark__status">{{ stateLabel }}</span>
            </div>
            <ul class="agentenblick-benchmark__privacy">
                <li>{{ $t('agentenblick.benchmark.privacy.aggregate') }}</li>
                <li>{{ $t('agentenblick.benchmark.privacy.journey') }}</li>
                <li>{{ $t('agentenblick.benchmark.privacy.excluded') }}</li>
                <li>{{ $t('agentenblick.benchmark.privacy.threshold') }}</li>
                <li>{{ $t('agentenblick.benchmark.privacy.retention') }}</li>
            </ul>
            <p class="agentenblick-index__hint"><a href="https://shopware.aithos.app/methodology" target="_blank" rel="noopener noreferrer">{{ $t('agentenblick.benchmark.methodology') }}</a></p>
            <dl class="agentenblick-benchmark__metadata">
                <dt>{{ $t('agentenblick.benchmark.installation') }}</dt><dd><code>{{ status.installationId }}</code></dd>
                <template v-if="status.consentAt"><dt>{{ $t('agentenblick.benchmark.consentAt') }}</dt><dd>{{ date(status.consentAt) }} \xB7 {{ status.locale }} \xB7 v{{ status.consentVersion }}</dd></template>
            </dl>
            <div v-if="(!status.enabled || needsConsentUpdate) && !status.pendingWithdrawal && canEdit" class="agentenblick-benchmark__consent">
                <label><input v-model="consent" type="checkbox" :disabled="busy || status.offline || identityConflict" /> <span>{{ $t('agentenblick.benchmark.consent') }}</span></label>
                <mt-button variant="primary" :disabled="!canEnable" :is-loading="busy" @click="update('enable')">{{ $t(needsConsentUpdate ? 'agentenblick.benchmark.renewConsent' : 'agentenblick.benchmark.enable') }}</mt-button>
            </div>
            <div v-if="status.enabled && canEdit" class="agentenblick-benchmark__actions">
                <mt-button :disabled="!canTransmit" :is-loading="busy" @click="update('send')">{{ $t('agentenblick.benchmark.send') }}</mt-button>
                <mt-button :disabled="busy" @click="confirmWithdrawal = true">{{ $t('agentenblick.benchmark.disable') }}</mt-button>
            </div>
            <p v-if="!canEdit" class="agentenblick-index__hint">{{ $t('agentenblick.benchmark.editorRequired') }}</p>
        </mt-card>

        <mt-card position-identifier="agentenblick-benchmark-preview" :title="$t('agentenblick.benchmark.previewTitle')">
            <p>{{ $t('agentenblick.benchmark.previewHint') }}</p>
            <p class="agentenblick-index__hint">{{ $t('agentenblick.benchmark.previewPrivacy') }}</p>
            <p v-if="preview && preview.period" class="agentenblick-benchmark__period">{{ $t('agentenblick.benchmark.period') }}: <strong>{{ preview.period }}</strong></p>
            <p v-if="!preview || !preview.items || !preview.items.length" class="agentenblick-index__hint">{{ $t('agentenblick.benchmark.noPreview') }}</p>
            <details v-for="item in (preview && preview.items) || []" :key="item.channelId" class="agentenblick-benchmark__payload">
                <summary><span>{{ $t('agentenblick.benchmark.channel') }} <code>{{ item.channelId }}</code></span><span>{{ rowStatus(item.status, item.resultCode) }}</span></summary>
                <p class="agentenblick-index__hint">SHA-256: <code>{{ item.sha256 }}</code></p>
                <pre tabindex="0" :aria-label="$t('agentenblick.benchmark.exactJson')">{{ item.json }}</pre>
            </details>
            <div class="agentenblick-benchmark__actions"><mt-button :disabled="busy" @click="load">{{ $t('agentenblick.benchmark.refreshLocal') }}</mt-button></div>
        </mt-card>

        <mt-card position-identifier="agentenblick-benchmark-history" :title="$t('agentenblick.benchmark.historyTitle')">
            <p class="agentenblick-index__hint">{{ $t('agentenblick.benchmark.historyHint') }}</p>
            <p v-if="!history.length" class="agentenblick-index__hint">{{ $t('agentenblick.benchmark.noHistory') }}</p>
            <div v-else class="agentenblick-index__table-wrap">
                <table class="agentenblick-index__table">
                    <thead><tr><th scope="col">{{ $t('agentenblick.benchmark.period') }}</th><th scope="col">{{ $t('agentenblick.benchmark.channel') }}</th><th scope="col">{{ $t('agentenblick.benchmark.status') }}</th><th scope="col">{{ $t('agentenblick.benchmark.sentAt') }}</th><th scope="col">{{ $t('agentenblick.benchmark.exactJson') }}</th></tr></thead>
                    <tbody><tr v-for="item in history" :key="\`\${item.period}:\${item.channelId}\`">
                        <td>{{ item.period }}</td><td><code>{{ item.channelId }}</code></td><td>{{ rowStatus(item.status, item.resultCode) }}</td><td>{{ date(item.sentAt) }}</td>
                        <td><details class="agentenblick-benchmark__history-payload"><summary>{{ $t('agentenblick.benchmark.inspect') }}</summary><p class="agentenblick-index__hint">SHA-256: <code>{{ item.sha256 }}</code></p><pre tabindex="0" :aria-label="$t('agentenblick.benchmark.exactJson')">{{ item.json }}</pre></details></td>
                    </tr></tbody>
                </table>
            </div>
        </mt-card>

        <mt-card position-identifier="agentenblick-benchmark-comparison" :title="$t('agentenblick.benchmark.comparisonTitle')">
            <p>{{ $t('agentenblick.benchmark.comparisonHint') }}</p>
            <p class="agentenblick-index__hint">{{ $t('agentenblick.benchmark.comparisonNetwork') }}</p>
            <div class="agentenblick-benchmark__actions"><mt-button :disabled="!canCompare" :is-loading="comparing" @click="compare">{{ $t('agentenblick.benchmark.compare') }}</mt-button><a href="https://shopware.aithos.app" target="_blank" rel="noopener noreferrer">{{ $t('agentenblick.benchmark.openDashboard') }}</a></div>
            <template v-if="comparison">
                <mt-banner v-if="comparison.report && comparison.report.demo" variant="attention">{{ $t('agentenblick.benchmark.demo') }}</mt-banner>
                <p v-if="!comparisonCells.length" class="agentenblick-index__hint">{{ comparisonMessage }}</p>
                <div v-else class="agentenblick-index__table-wrap"><table class="agentenblick-index__table">
                    <thead><tr><th scope="col">{{ $t('agentenblick.benchmark.period') }}</th><th scope="col">{{ $t('agentenblick.benchmark.cohort') }}</th><th scope="col">{{ $t('agentenblick.benchmark.localShare') }}</th><th scope="col">{{ $t('agentenblick.benchmark.median') }}</th><th scope="col">{{ $t('agentenblick.benchmark.quartiles') }}</th><th scope="col">{{ $t('agentenblick.benchmark.installations') }}</th></tr></thead>
                    <tbody><tr v-for="cell in comparisonCells" :key="\`\${cell.period}:\${cell.cohortId}\`"><td>{{ cell.period }}</td><td><code>{{ cell.cohortId }}</code></td><td><div v-for="local in cell.local" :key="local.channelId">{{ percent(local.aiSessionsPct) }} <small><code>{{ local.channelId }}</code></small></div><span v-if="!cell.local.length">\u2014</span></td><td>{{ percent(cell.metrics.aiSessionsPct.median) }}</td><td>{{ percent(cell.metrics.aiSessionsPct.p25) }}\u2013{{ percent(cell.metrics.aiSessionsPct.p75) }}</td><td>{{ cell.metrics.aiSessionsPct.installations }}</td></tr></tbody>
                </table></div>
            </template>
        </mt-card>
    </template>

    <sw-modal v-if="confirmWithdrawal && canEdit" :title="$t('agentenblick.benchmark.withdrawTitle')" @modal-close="closeWithdrawal">
        <p>{{ $t('agentenblick.benchmark.withdrawText') }}</p>
        <p v-if="status && status.offline" class="agentenblick-index__hint">{{ $t('agentenblick.benchmark.withdrawOffline') }}</p>
        <template #modal-footer>
            <mt-button :disabled="busy" @click="closeWithdrawal">{{ $t('agentenblick.actions.cancel') }}</mt-button>
            <mt-button variant="primary" :disabled="busy" :is-loading="busy" @click="update('disable')">{{ $t('agentenblick.benchmark.confirmWithdrawal') }}</mt-button>
        </template>
    </sw-modal>
</div>
`;Shopware.Component.register("agentenblick-benchmark",{template:l,inject:["agentenblickApiService","acl"],mixins:[Shopware.Mixin.getByName("notification")],data(){return{status:null,preview:null,history:[],comparison:null,consent:!1,confirmWithdrawal:!1,loading:!1,busy:!1,comparing:!1,loadFailed:!1,sequence:0}},computed:{canEdit(){return this.acl.can("agentenblick.editor")},locale(){let e=this.$i18n.locale;return(typeof e=="string"?e:e?.value)?.startsWith("de")?"de-DE":"en-GB"},stateLabel(){return this.identityConflict?this.$t("agentenblick.benchmark.state.test_identity_conflict"):this.status?.pendingWithdrawal?this.$t("agentenblick.benchmark.state.withdrawal_pending"):this.status?.offline?this.$t("agentenblick.benchmark.state.offline"):this.needsConsentUpdate?this.$t("agentenblick.benchmark.state.consent_update_required"):this.status?.reasonCode==="registration_pending"?this.$t("agentenblick.benchmark.state.registration_pending"):this.$t(`agentenblick.benchmark.state.${this.status?.enabled?"ready":"disabled"}`)},identityConflict(){return this.status?.reasonCode==="test_identity_conflict"},requiredConsentVersion(){return String(this.status?.requiredConsentVersion||"2")},needsConsentUpdate(){return!!(this.status?.enabled&&(this.status.reasonCode==="consent_update_required"||String(this.status.consentVersion)!==this.requiredConsentVersion))},canEnable(){return this.canEdit&&this.consent&&!this.busy&&!this.status?.offline&&!this.status?.pendingWithdrawal&&!this.identityConflict&&this.requiredConsentVersion==="2"},canTransmit(){return this.canEdit&&this.status?.enabled&&!this.status?.offline&&!this.status?.pendingWithdrawal&&!this.identityConflict&&!this.needsConsentUpdate&&!this.busy},canCompare(){return this.status?.enabled&&!this.status?.offline&&!this.status?.pendingWithdrawal&&!this.identityConflict&&!this.needsConsentUpdate&&!this.comparing&&!this.busy},comparisonCells(){let e=this.comparison?.report;return!e||!Array.isArray(e.cells)?[]:e.cells.filter(n=>this.published(n.metrics?.aiSessionsPct)).map(n=>({...n,cohort:e.cohorts?.find(t=>t.id===n.cohortId),local:(this.comparison.local||[]).filter(t=>t.period===n.period&&t.cohortId===n.cohortId)}))},comparisonMessage(){let e=this.comparison?.reason,n=`agentenblick.benchmark.comparisonReason.${e}`;return e&&this.$te(n)?this.$t(n):this.$t("agentenblick.benchmark.insufficient")}},created(){this.load()},beforeUnmount(){this.sequence+=1},methods:{async load(){let e=++this.sequence;this.loading=!0,this.loadFailed=!1;try{let n=await this.agentenblickApiService.getBenchmarkStatus(),[t,i]=await Promise.all([n.reasonCode==="test_identity_conflict"?Promise.resolve(null):this.agentenblickApiService.getBenchmarkPreview(),this.agentenblickApiService.getBenchmarkHistory()]);if(e!==this.sequence)return;this.status=n,this.preview=t,this.history=i.items||[]}catch{e===this.sequence&&(this.loadFailed=!0)}finally{e===this.sequence&&(this.loading=!1)}},async update(e){if(!(!this.canEdit||this.busy||!this.status)&&!(e==="enable"&&!this.canEnable)&&!(e==="send"&&!this.canTransmit)){this.busy=!0;try{let n=e==="enable"?{consent:!0,consentVersion:"2",locale:this.locale}:{},t=await this.agentenblickApiService.updateBenchmark(e,n);if(this.confirmWithdrawal=!1,this.consent=!1,this.comparison=null,await this.load(),this.loadFailed)throw new Error("Reload failed");if(e==="send"&&["failed","registration_pending","consent_update_required"].includes(t?.status)){this.createNotificationError({message:this.$t("agentenblick.benchmark.error.send")});return}this.createNotificationSuccess({message:this.$t(`agentenblick.benchmark.success.${e}`)})}catch{this.createNotificationError({message:this.$t("agentenblick.benchmark.error.update")})}finally{this.busy=!1}}},async compare(){if(this.canCompare){this.comparing=!0;try{this.comparison=await this.agentenblickApiService.getBenchmarkComparison()}catch{this.createNotificationError({message:this.$t("agentenblick.benchmark.error.compare")})}finally{this.comparing=!1}}},published(e){return e&&Number.isSafeInteger(e.installations)&&e.installations>=5&&[e.p25,e.median,e.p75].every(n=>typeof n=="number"&&Number.isFinite(n)&&n>=0&&n<=100)&&e.p25<=e.median&&e.median<=e.p75},percent(e){return typeof e=="number"&&Number.isFinite(e)?new Intl.NumberFormat(this.locale,{style:"percent",maximumFractionDigits:1}).format(e/100):"\u2014"},date(e){if(!e)return"\u2014";let n=String(e).replace(" ","T"),t=new Date(/(?:Z|[+-]\d{2}:?\d{2})$/.test(n)?n:`${n}Z`);return Number.isNaN(t.getTime())?"\u2014":new Intl.DateTimeFormat(this.locale,{timeZone:"UTC",day:"2-digit",month:"short",year:"numeric",hour:"2-digit",minute:"2-digit"}).format(t)},rowStatus(e,n){if(e==="failed"&&["http_400","http_409","http_410","http_413","http_422"].includes(n))return this.$t("agentenblick.benchmark.submission.rejected");let t=`agentenblick.benchmark.submission.${e}`;return this.$te(t)?this.$t(t):this.$t("agentenblick.benchmark.submission.unknown")},closeWithdrawal(){this.busy||(this.confirmWithdrawal=!1)}}});var c=`<div class="agentenblick-journey">
    <p class="agentenblick-journey__intro">{{ $t('agentenblick.journey.description') }}</p>
    <mt-banner v-if="!canView" variant="critical">{{ $t('agentenblick.errors.permission') }}</mt-banner>
    <div v-else-if="loading" class="agentenblick-index__loading" role="status">
        <sw-loader /><span class="agentenblick-index__sr-only">{{ $t('agentenblick.general.loading') }}</span>
    </div>
    <mt-banner v-else-if="failed" variant="critical">
        <p>{{ $t('agentenblick.journey.loadFailed') }}</p>
        <mt-button @click="load">{{ $t('agentenblick.actions.retry') }}</mt-button>
    </mt-banner>
    <template v-else-if="canView">
        <template v-if="mode === 'funnel' && summary">
            <p class="agentenblick-journey__hint">{{ $t('agentenblick.journey.funnelHint') }}</p>
            <p class="agentenblick-journey__counts">{{ $t('agentenblick.journey.sessionCounts', { closed: count(summary.closedSessions), open: count(summary.openSessions) }) }}</p>
            <mt-card v-for="group in proofGroups" :key="group.key" :position-identifier="\`agentenblick-funnel-\${group.key}\`" :title="label('journey.proofGroup', group.key)">
                <p class="agentenblick-journey__hint">{{ label('journey.proofHint', group.key) }}</p>
                <div class="agentenblick-journey__proof-grid">
                    <section v-for="row in group.rows" :key="row.evidence">
                        <h3><span class="agentenblick-proof" :class="group.key === 'verified' ? 'agentenblick-proof--verified' : 'agentenblick-proof--unverified'">{{ label('evidence', row.evidence) }}</span></h3>
                        <p class="agentenblick-journey__hint">{{ $t('agentenblick.journey.groupSessions', { count: count(row.sessions) }) }}</p>
                        <ol class="agentenblick-journey__funnel">
                            <li v-for="step in stages" :key="step">
                                <span>{{ stage(step) }}</span><strong>{{ count(row.steps?.[step]) }}</strong>
                                <span class="agentenblick-journey__bar" aria-hidden="true"><i :style="{ width: \`\${barWidth(row.steps?.[step], row.sessions)}%\` }"></i></span>
                                <span class="agentenblick-journey__fraction">{{ percent(row.steps?.[step], row.sessions) }}</span>
                            </li>
                        </ol>
                    </section>
                </div>
            </mt-card>
            <p v-if="!proofGroups.length" class="agentenblick-index__empty">{{ $t('agentenblick.journey.empty') }}</p>
        </template>

        <mt-card v-if="mode === 'failures' && summary" position-identifier="agentenblick-failures" :title="$t('agentenblick.tabs.failures')">
            <p class="agentenblick-journey__hint">{{ $t('agentenblick.journey.failuresHint') }}</p>
            <div class="agentenblick-index__table-wrap">
                <table class="agentenblick-index__table">
                    <caption class="agentenblick-index__sr-only">{{ $t('agentenblick.journey.failuresCaption') }}</caption>
                    <thead><tr><th scope="col">{{ $t('agentenblick.journey.failure') }}</th><th scope="col">{{ $t('agentenblick.table.evidence') }}</th><th scope="col">{{ $t('agentenblick.journey.affected') }}</th><th scope="col">{{ $t('agentenblick.journey.eligible') }}</th><th scope="col">{{ $t('agentenblick.journey.rate') }}</th></tr></thead>
                    <tbody>
                        <tr v-for="(row, index) in failures" :key="\`\${row.code}:\${row.evidence || ''}:\${index}\`">
                            <th scope="row">{{ label('journey.failureCode', row.code) }}<span v-if="row.stage" class="agentenblick-index__subline">{{ stage(row.stage) }}</span><code v-if="row.route" class="agentenblick-index__subline">{{ row.route }}</code></th>
                            <td>{{ row.evidence ? label('evidence', row.evidence) : $t('agentenblick.journey.allProofs') }}</td>
                            <td>{{ count(row.sessions) }}</td><td>{{ count(row.eligibleSessions) }}</td><td>{{ percent(row.sessions, row.eligibleSessions) }}</td>
                        </tr>
                        <tr v-if="!failures.length"><td colspan="5" class="agentenblick-index__empty">{{ $t('agentenblick.journey.noFailures') }}</td></tr>
                    </tbody>
                </table>
            </div>
            <details v-if="unknownFailures.length" class="agentenblick-journey__unknown">
                <summary>{{ $t('agentenblick.journey.unknownFailures', { count: count(unknownFailures.length) }) }}</summary>
                <ul><li v-for="row in unknownFailures" :key="\`\${row.code}:\${row.evidence}\`">{{ label('journey.failureCode', row.code) }} \xB7 {{ label('evidence', row.evidence) }}: \u2014</li></ul>
            </details>
        </mt-card>

        <mt-card v-if="mode === 'failures' && summary" position-identifier="agentenblick-error-routes" :title="$t('agentenblick.journey.errorRoutes')">
            <p class="agentenblick-journey__hint">{{ $t('agentenblick.journey.errorRoutesHint') }}</p>
            <div class="agentenblick-index__table-wrap"><table class="agentenblick-index__table">
                <thead><tr><th scope="col">{{ $t('agentenblick.journey.route') }}</th><th scope="col">{{ $t('agentenblick.journey.step') }}</th><th scope="col">HTTP</th><th scope="col">{{ $t('agentenblick.table.evidence') }}</th><th scope="col">{{ $t('agentenblick.table.requests') }}</th></tr></thead>
                <tbody><tr v-for="(row, index) in errorRoutes" :key="index"><th scope="row"><code>{{ row.route || $t('agentenblick.journey.unknownRoute') }}</code></th><td>{{ stage(row.stage) }}</td><td>{{ row.status }}</td><td>{{ label('evidence', row.evidence) }}</td><td>{{ count(row.requests) }}</td></tr>
                    <tr v-if="!errorRoutes.length"><td colspan="5">{{ $t('agentenblick.journey.noErrorRoutes') }}</td></tr>
                </tbody>
            </table></div>
        </mt-card>

        <mt-card v-if="mode === 'journeys'" position-identifier="agentenblick-journeys" :title="$t('agentenblick.tabs.journeys')">
            <p class="agentenblick-journey__hint">{{ $t('agentenblick.journey.listHint') }}</p>
            <div class="agentenblick-index__table-wrap">
                <table class="agentenblick-index__table agentenblick-journey__sessions">
                    <caption class="agentenblick-index__sr-only">{{ $t('agentenblick.journey.listCaption') }}</caption>
                    <thead><tr><th scope="col">{{ $t('agentenblick.table.agent') }}</th><th scope="col">{{ $t('agentenblick.table.evidence') }}</th><th scope="col">{{ $t('agentenblick.journey.lastStage') }}</th><th scope="col">{{ $t('agentenblick.journey.maxStage') }}</th><th scope="col">{{ $t('agentenblick.journey.failure') }}</th><th scope="col">{{ $t('agentenblick.journey.state') }}</th><th scope="col">{{ $t('agentenblick.journey.timeline') }}</th></tr></thead>
                    <tbody>
                        <tr v-for="session in sessions" :key="session.id">
                            <th scope="row"><strong>{{ session.agent || session.operator }}</strong><span class="agentenblick-index__subline">{{ session.operator }} \xB7 {{ label('kind', session.kind) }}</span><span class="agentenblick-index__subline">{{ date(session.lastSeen) }}</span></th>
                            <td><span class="agentenblick-proof" :class="proofGroup(session.evidence) === 'verified' ? 'agentenblick-proof--verified' : 'agentenblick-proof--unverified'">{{ label('evidence', session.evidence) }}</span><span class="agentenblick-index__subline">{{ label('journey.linkage', session.linkage) }}</span></td>
                            <td>{{ stage(session.lastStage) }}</td><td>{{ stage(session.maxStage) }}</td>
                            <td><ul v-if="failureCodes(session.failures).length" class="agentenblick-journey__list"><li v-for="code in failureCodes(session.failures)" :key="code">{{ label('journey.failureCode', code) }}</li></ul><span v-else>{{ $t('agentenblick.journey.noneObserved') }}</span></td>
                            <td>{{ $t(session.closedAt ? 'agentenblick.journey.closed' : 'agentenblick.journey.open') }}</td>
                            <td><mt-button size="small" @click="openDetail(session)">{{ $t('agentenblick.journey.inspect') }}</mt-button></td>
                        </tr>
                        <tr v-if="!sessions.length"><td colspan="7" class="agentenblick-index__empty">{{ $t('agentenblick.journey.empty') }}</td></tr>
                    </tbody>
                </table>
            </div>
            <div v-if="total" class="agentenblick-index__pagination">
                <span>{{ $t('agentenblick.journey.pagination', { page, pages: pageCount, total: count(total) }) }}</span>
                <mt-button size="small" :disabled="page <= 1 || loading" @click="changePage(-1)">{{ $t('agentenblick.actions.previous') }}</mt-button>
                <mt-button size="small" :disabled="page >= pageCount || loading" @click="changePage(1)">{{ $t('agentenblick.actions.next') }}</mt-button>
            </div>
        </mt-card>
    </template>

    <sw-modal v-if="selectedId && canView" class="agentenblick-journey__modal" variant="large" :title="$t('agentenblick.journey.timeline')" @modal-close="closeDetail">
        <div v-if="detailLoading" class="agentenblick-index__loading" role="status"><sw-loader /><span class="agentenblick-index__sr-only">{{ $t('agentenblick.general.loading') }}</span></div>
        <mt-banner v-else-if="detailFailed" variant="critical">{{ $t('agentenblick.journey.detailFailed') }}</mt-banner>
        <template v-else-if="detail">
            <h3>{{ detail.session.agent || detail.session.operator }}</h3>
            <p class="agentenblick-journey__hint">{{ label('evidence', detail.session.evidence) }} \xB7 {{ label('journey.linkage', detail.session.linkage) }}</p>
            <p class="agentenblick-journey__hint">{{ label('journey.linkageHint', detail.session.linkage) }}</p>
            <dl class="agentenblick-journey__facts">
                <div><dt>{{ $t('agentenblick.journey.firstSeen') }}</dt><dd>{{ date(detail.session.firstSeen) }}</dd></div>
                <div><dt>{{ $t('agentenblick.table.lastSeen') }}</dt><dd>{{ date(detail.session.lastSeen) }}</dd></div>
                <div><dt>{{ $t('agentenblick.journey.lastStage') }}</dt><dd>{{ stage(detail.session.lastStage) }}</dd></div>
                <div><dt>{{ $t('agentenblick.journey.maxStage') }}</dt><dd>{{ stage(detail.session.maxStage) }}</dd></div>
            </dl>
            <p v-if="detail.session.reason" class="agentenblick-journey__hint">{{ $t('agentenblick.journey.reason') }}: {{ label('journey.reasonCode', detail.session.reason) }}</p>
            <p v-if="stepNames(detail.session.steps).length" class="agentenblick-journey__hint">{{ $t('agentenblick.journey.observedSteps') }}: {{ stepNames(detail.session.steps).join(' \xB7 ') }}</p>
            <p v-if="detail.session.orderIds?.length" class="agentenblick-journey__hint">{{ $t('agentenblick.journey.orderIds') }}: <code v-for="id in detail.session.orderIds" :key="id" class="agentenblick-journey__order">{{ id }}</code></p>
            <p class="agentenblick-journey__hint">{{ $t('agentenblick.journey.timelineHint') }}</p>
            <mt-banner v-if="detail.truncated" variant="attention">{{ $t('agentenblick.journey.truncated') }}</mt-banner>
            <ol class="agentenblick-journey__timeline">
                <li v-for="(item, index) in detail.items" :key="\`\${item.at}:\${index}\`">
                    <div class="agentenblick-journey__event-head"><time>{{ date(item.at) }}</time><strong>{{ stage(item.stage) }}</strong><span>{{ item.method || '\u2014' }} \xB7 {{ item.status || '\u2014' }}</span></div>
                    <code class="agentenblick-journey__route">{{ item.route || $t('agentenblick.journey.unknownRoute') }}</code>
                    <p>{{ label('evidence', item.evidence) }}<template v-if="item.linkage"> \xB7 {{ label('journey.linkage', item.linkage) }}</template></p>
                    <p v-if="item.reason">{{ $t('agentenblick.journey.reason') }}: {{ label('journey.reasonCode', item.reason) }}</p>
                    <p>{{ $t('agentenblick.journey.knownOutcomes') }}: {{ (item.knownOutcomes || []).map((value) => label('journey.outcome', value)).join(' \xB7 ') || $t('agentenblick.journey.unknown') }}</p>
                    <ul v-if="failureCodes(item.failures).length" class="agentenblick-journey__list"><li v-for="code in failureCodes(item.failures)" :key="code">{{ label('journey.failureCode', code) }}</li></ul>
                    <p v-if="item.orderId">{{ $t('agentenblick.journey.orderId') }}: <code>{{ item.orderId }}</code></p>
                </li>
            </ol>
            <p v-if="!detail.items?.length" class="agentenblick-index__empty">{{ $t('agentenblick.journey.noTimeline') }}</p>
        </template>
        <template #modal-footer><mt-button @click="closeDetail">{{ $t('agentenblick.journey.close') }}</mt-button></template>
    </sw-modal>
</div>
`;var s=["discover","browse","search","product","cart","checkout","order"],k=["training_crawler","search_crawler","user_fetch","browsing_agent","automation_unknown"];Shopware.Component.register("agentenblick-journey",{template:c,inject:["agentenblickApiService","acl"],props:{mode:{type:String,required:!0},filters:{type:Object,required:!0}},data(){return{summary:null,sessions:[],total:0,page:1,limit:20,loading:!1,failed:!1,sequence:0,selectedId:null,detail:null,detailLoading:!1,detailFailed:!1,detailSequence:0}},computed:{canView(){return this.acl.can("agentenblick.viewer")},locale(){let e=this.$i18n.locale;return(typeof e=="string"?e:e?.value)?.startsWith("de")?"de-DE":"en-GB"},stages(){return s},pageCount(){return Math.max(1,Math.ceil(this.total/this.limit))},proofGroups(){return["verified","declared","other"].map(e=>({key:e,rows:(this.summary?.funnel||[]).filter(n=>this.proofGroup(n.evidence)===e)})).filter(e=>e.rows.length)},failures(){return(this.summary?.failures||[]).filter(e=>e.eligibleSessions>0||e.sessions>0)},unknownFailures(){return(this.summary?.failures||[]).filter(e=>!(e.eligibleSessions>0)&&!(e.sessions>0))},errorRoutes(){return this.summary?.errorRoutes||[]}},watch:{mode(){this.page=1,this.closeDetail(),this.load()},filters:{deep:!0,handler(){this.page=1,this.closeDetail(),this.load()}}},created(){this.load()},beforeUnmount(){this.sequence+=1,this.detailSequence+=1},methods:{async load(){if(!this.canView||!["funnel","journeys","failures"].includes(this.mode))return;let e=++this.sequence;this.loading=!0,this.failed=!1;try{if(this.mode==="journeys"){let n=await this.agentenblickApiService.getJourneySessions({...this.filters,page:this.page,limit:this.limit});if(e!==this.sequence)return;this.sessions=(n.items||[]).filter(t=>this.isAgent(t)),this.total=Number.isSafeInteger(n.total)&&n.total>=0?n.total:0}else{let n=await this.agentenblickApiService.getJourneySummary(this.filters);if(e!==this.sequence)return;this.summary=n}}catch{e===this.sequence&&(this.failed=!0)}finally{e===this.sequence&&(this.loading=!1)}},changePage(e){let n=this.page+e;this.loading||n<1||n>this.pageCount||(this.page=n,this.load())},async openDetail(e){if(!this.canView||!this.isAgent(e)||!e.id)return;let n=++this.detailSequence;this.selectedId=e.id,this.detail=null,this.detailLoading=!0,this.detailFailed=!1;try{let t=await this.agentenblickApiService.getJourneySession(e.id,this.filters);if(n!==this.detailSequence)return;if(!this.isAgent(t.session))throw new Error("Agent session required");this.detail=t}catch{n===this.detailSequence&&(this.detailFailed=!0)}finally{n===this.detailSequence&&(this.detailLoading=!1)}},closeDetail(){this.detailSequence+=1,this.selectedId=null,this.detail=null,this.detailLoading=!1,this.detailFailed=!1},isAgent(e){return e&&k.includes(e.kind)},proofGroup(e){return["verified_signature","verified_ip","verified_rdns"].includes(e)?"verified":e==="declared_unverified"?"declared":"other"},label(e,n){let t=`agentenblick.${e}.${n}`;return n&&this.$te(t)?this.$t(t):n||this.$t("agentenblick.journey.unknown")},stage(e){return s.includes(e)?this.label("journey.stage",e):this.$t("agentenblick.journey.unknown")},count(e){return typeof e=="number"&&Number.isSafeInteger(e)&&e>=0?new Intl.NumberFormat(this.locale).format(e):"\u2014"},percent(e,n){return!Number.isSafeInteger(e)||!Number.isSafeInteger(n)||e<0||n<=0||e>n?"\u2014":new Intl.NumberFormat(this.locale,{style:"percent",maximumFractionDigits:1}).format(e/n)},barWidth(e,n){return!Number.isSafeInteger(e)||!Number.isSafeInteger(n)||e<0||n<=0||e>n?0:e/n*100},date(e){if(!e)return"\u2014";let n=String(e).replace(" ","T"),t=new Date(/(?:Z|[+-]\d{2}:?\d{2})$/.test(n)?n:`${n}Z`);return Number.isNaN(t.getTime())?"\u2014":new Intl.DateTimeFormat(this.locale,{timeZone:"UTC",day:"2-digit",month:"short",hour:"2-digit",minute:"2-digit",second:"2-digit"}).format(t)},failureCodes(e){return Array.isArray(e)?e.filter(n=>typeof n=="string"):[]},stepNames(e){return s.filter(n=>Array.isArray(e)?e.includes(n):e?.[n]===!0||e?.[n]>0).map(n=>this.stage(n))}}});var{ApiService:r}=Shopware.Classes,a=class extends r{constructor(n,t){super(n,t,"_action/agentenblick","application/json")}getSummary(n){return this.get("summary",n)}getAgents(n){return this.get("agents",n)}getPendingRanges(){return this.get("ranges/pending")}getJourneySummary(n){return this.get("journey/summary",n)}getJourneySessions(n){return this.get("journey/sessions",n)}getJourneySession(n,t){return this.get(`journey/sessions/${encodeURIComponent(n)}`,t)}getBenchmarkStatus(){return this.get("benchmark/status")}getBenchmarkPreview(n={}){return this.get("benchmark/preview",n)}getBenchmarkHistory(){return this.get("benchmark/history")}getBenchmarkComparison(){return this.get("benchmark/comparison")}updateBenchmark(n,t={}){return["enable","disable","send"].includes(n)?this.httpClient.post(`${this.getApiBasePath()}/benchmark/${n}`,t,{headers:this.getBasicHeaders()}).then(r.handleResponse):Promise.reject(new Error("Invalid benchmark action"))}get(n,t={}){return this.httpClient.get(`${this.getApiBasePath()}/${n}`,{headers:this.getBasicHeaders(),params:t}).then(r.handleResponse)}approveRanges(n,t){return this.httpClient.post(`${this.getApiBasePath()}/ranges/${encodeURIComponent(n)}/approve`,{version:t},{headers:this.getBasicHeaders()}).then(r.handleResponse)}exportAgents(n){return this.httpClient.get(`${this.getApiBasePath()}/export`,{headers:this.getBasicHeaders({Accept:"text/csv"}),params:n,responseType:"blob"}).then(t=>t.data)}};var d={agentenblick:{general:{title:"Agentenblick",menu:"AI agents",description:"See the crawlers and agents visiting your shop, with the evidence behind each identity.",loading:"Loading observed traffic\u2026",empty:"No requests observed for this period and sales channel yet.",noAgents:"No crawler or agent traffic observed for this selection.",scope:"Only requests reaching Shopware are observed. Ordinary-looking browser agents may remain unidentified. Sessions are provisional; a shared agent key can group several visits. All dates use UTC."},actions:{refresh:"Refresh",export:"Export agents CSV",retry:"Try again",previous:"Previous",next:"Next",cancel:"Cancel"},tabs:{overview:"Overview",agents:"Agents",benchmark:"Benchmark",funnel:"Funnel",journeys:"Journeys",failures:"Failures"},filters:{period:"Period",lastDays:"Last {days} days",channel:"Sales channel",allChannels:"All sales channels"},metrics:{share:"Observed agent share",shareHint:"Share of all observed sessions, including declared identities.",agentSessions:"Agent sessions",agentSessionsHint:"Crawlers and agents, verified or declared.",agentRequests:"Agent requests",requestsHint:"Out of {total} observed requests.",allSessions:"All sessions",allSessionsHint:"Humans, unidentified traffic and agents."},trend:{title:"Sessions over time",all:"All sessions",agents:"Agent sessions",accessible:"Daily total and agent sessions. Exact values are available in the table below.",values:"Show daily values",date:"Date (UTC)"},breakdown:{kinds:"Types of agent",evidence:"Identity evidence",proofHint:"Requests retain their own evidence. Sessions use the strongest proof observed during collection; deferred verification applies stricter rules."},table:{caption:"Observed agents and their identity evidence",agent:"Agent / operator",kind:"Type",evidence:"Evidence",requests:"Requests",sessions:"Sessions",lastSeen:"Last seen (UTC)",routes:"Top routes",showRoutes:"Show routes",pagination:"Page {page} of {pages} \xB7 {total} agents",proofHint:"A declared identity is not verified and is not proof of impersonation. Mixed evidence is shown separately, with request counts.",retentionHint:"Last seen and top routes use retained request details. A generic route means Shopware did not expose a resolved route, for example on a cache hit."},kind:{training_crawler:"Training crawler",search_crawler:"Search crawler",user_fetch:"User-triggered fetch",browsing_agent:"Browsing agent",automation_unknown:"Unidentified automation",human_or_unknown:"Human or unknown",mixed:"Multiple types"},evidence:{verified_signature:"Signature verified",verified_ip:"IP range verified",verified_rdns:"Reverse DNS verified",declared_unverified:"Declared, unverified",none:"No identity evidence",mixed:"Mixed evidence"},proxy:{title:"Check your trusted proxy configuration",description:"Most resolved client IPs in the current observation window belong to known CDN ranges. IP verification may be using the CDN address instead of the visitor address. This is not proof of impersonation.",remediation:"Configure only your actual proxy addresses in TRUSTED_PROXIES and the forwarded headers trusted by Symfony. The proxy must overwrite visitor-supplied headers. Verify the result of Request::getClientIp(). The warning clears when a new hourly window no longer has a CDN majority."},ranges:{title:"IP range updates awaiting review",description:"These official lists changed by more than 30%. The existing ranges remain active until an editor approves the proposed version.",current:"Current ranges",proposed:"Proposed ranges",updated:"Downloaded (UTC)",review:"Review change",editorRequired:"Editor permission required",confirmTitle:"Approve IP ranges for {agent}",confirmText:"Replace the current {current} ranges with the proposed {proposed} ranges? Review the differences and the operator's official source before applying this version.",source:"Open official source",added:"Added ({count})",removed:"Removed ({count})",truncated:"The first 50 added and removed ranges are shown. Check the official source for the complete list.",approve:"Approve this version",approved:"The reviewed IP range version is now active.",changed:"The proposed ranges changed while you were reviewing them. Review the refreshed version before approving."},errors:{load:"Traffic could not be loaded for this selection. Please try again.",export:"The CSV could not be downloaded. Try again or check your viewing permission.",approve:"The IP ranges could not be approved. Check your editor permission and try again.",permission:"You do not have permission to view Agentenblick."},benchmark:{title:"Contribute to the Shopware benchmark",description:"Participation is optional and disabled by default. Review the exact weekly payload below before enabling automatic submission to the Aithos benchmark.",loading:"Loading local benchmark settings\u2026",demo:"Demonstration mode: synthetic data is kept separate from merchant benchmark submissions.",offline:"Offline mode is active. No benchmark network requests are made. Previews and the local journal remain available.",withdrawalPending:"Participation has stopped locally. Server-side deletion is pending and will be retried when network access is available.",privacy:{aggregate:"Only completed ISO-week aggregates are sent: percentage shares rounded to 0.1 point, broad segments and a random installation identifier. Sales-channel keys are pseudonymised in the transmitted payload.",excluded:"No raw IP, visitor or session identifier, shop domain, request body, query string, order identifier or individual request is sent.",threshold:"A denominator below 10 sessions suppresses that shop metric. A published benchmark metric requires at least 5 eligible installations; several channels from one shop count as one contributor.",retention:"Submissions are retained for 13 months. Participation can be withdrawn; this stops future submissions and requests deletion of that installation\u2019s retained submissions.",journey:"Version 2 adds independently observed journey-step shares and named failure shares, split by verified and declared agents. Only eligible sessions with known outcomes enter failure denominators; no missing step is inferred."},methodology:"Read the public methodology and privacy rules",installation:"Random installation ID",consentAt:"Consent recorded (UTC)",consent:"I have reviewed the payload preview and methodology. I agree to send the described weekly traffic, journey and failure aggregates to the Aithos benchmark under consent version 2. No individual request, session or order is sent. I can withdraw at any time.",enable:"Enable weekly participation",send:"Send pending weekly aggregates",disable:"Withdraw participation",editorRequired:"Editor permission is required to enable participation, send aggregates or withdraw.",previewTitle:"Exact payload preview",previewHint:"This preview is generated locally. Opening or refreshing it does not send data to the benchmark.",previewPrivacy:"The JSON below shows the exact payload bytes, including suppressed values. Local channel identifiers shown in this interface are not transmitted as such.",period:"ISO week",noPreview:"There are no completed weeks available for a payload yet.",channel:"Local sales channel",exactJson:"Exact JSON payload",refreshLocal:"Refresh local preview and journal",historyTitle:"Submission journal",historyHint:"Inspect the exact payload and its hash for each local submission. Dates use UTC.",noHistory:"No submissions have been prepared or sent yet.",status:"Status",sentAt:"Sent (UTC)",inspect:"Inspect payload",comparisonTitle:"Compare with a published cohort",comparisonHint:"Published figures use comparable, disjoint cohorts. The median is an installation-level share; quartiles describe the middle half of published values.",comparisonNetwork:"Fetching the benchmark contacts shopware.aithos.app. This action requires participation to be enabled and offline mode to be off.",compare:"Fetch published benchmark",openDashboard:"Open the research dashboard",insufficient:"No comparable metric can be displayed. It may be unpublished, unavailable, or below the five-installation threshold.",cohort:"Published cohort",median:"Agent share: median",quartiles:"25th\u201375th percentile",installations:"Eligible installations",withdrawTitle:"Withdraw benchmark participation",withdrawText:"Stop future submissions and request deletion of retained submissions for this installation? Local traffic measurement continues.",withdrawOffline:"Offline mode prevents the deletion request from being sent now. The request will remain pending until network access is available.",confirmWithdrawal:"Stop participation and request deletion",state:{withdrawal_pending:"Deletion pending",offline:"Offline",ready:"Participation enabled",disabled:"Participation disabled",registration_pending:"Registration pending",test_identity_conflict:"Real identity blocked in test",consent_update_required:"Consent update required"},submission:{draft:"Local draft",pending:"Awaiting submission",sent:"Sent",failed:"Failed; awaiting retry",unknown:"Unknown status",rejected:"Rejected; inspect the payload",expired:"Expired; outside the 13-week submission window",withdrawn:"Withdrawn; retained in local journal"},success:{enable:"Weekly benchmark participation is enabled.",disable:"Participation is stopped. Check the status of the deletion request.",send:"Pending aggregates have been processed. Check the submission journal for each result."},error:{load:"The local benchmark settings could not be loaded. Please try again.",update:"The action could not be confirmed. Refresh the local status and check your editor permission before trying again.",compare:"The benchmark could not be retrieved. Please try again later.",send:"The submission could not be completed. Inspect its status in the local journal."},localShare:"This shop: agent share by channel",comparisonReason:{demo_dashboard_authentication_required:"Demonstration benchmarks require partner authentication. Open the research dashboard to view synthetic cohorts.",offline:"Offline mode prevents fetching the benchmark.",disabled:"Enable participation before fetching a comparison.",withdrawal_pending:"A comparison is unavailable while the withdrawal request is pending."},identityConflict:"This test environment contains an existing real installation identity. Activation, submissions, comparisons and server deletion are blocked. Use a separate demonstration identity; do not reuse the production identity from a cloned database.",consentUpdate:"The benchmark now includes journey and failure shares. New submissions are paused until you explicitly accept consent version 2. Existing frozen documents remain unchanged; withdrawal is still available.",renewConsent:"Accept version 2 and resume participation"},journey:{description:"Local journeys of observed crawlers and agents. No human request or session detail is shown. All dates use UTC.",unknown:"Unknown",unknownRoute:"Unresolved route",loadFailed:"The journeys could not be loaded for this selection. Please try again.",detailFailed:"This journey is unavailable, outside the selected period/channel, or no longer retained.",funnelHint:"Each step counts closed agent sessions with that step actually observed. Skipped or unknown steps are not filled in. Reaching a page does not prove that an action succeeded.",sessionCounts:"{closed} closed sessions \xB7 {open} open sessions",groupSessions:"{count} sessions in this evidence group",empty:"No agent journeys are available for this selection.",proofGroup:{verified:"Verified agents",declared:"Declared agents",other:"Other evidence"},proofHint:{verified:"Identity confirmed by signature, IP range or reverse DNS. Each proof remains separate.",declared:"The agent announces an identity that has not been verified. This is not proof of impersonation.",other:"No confirmed operator identity. These sessions are excluded from verified-agent totals."},stage:{discover:"Discovery",browse:"Browse",search:"Search",product:"Product",cart:"Cart",checkout:"Checkout",order:"Order placed"},failure:"Observed failure",affected:"Affected sessions",eligible:"Sessions with known outcome",rate:"Share",allProofs:"All agent evidence",failuresCaption:"Named failures with their eligible session denominator",failuresHint:"Shares use only sessions for which the relevant outcome is known. Unknown outcomes, including cache hits without the corresponding event, are excluded. \u201C\u2014\u201D means no valid denominator; it never means success. A session can have several failures.",noFailures:"No named failures are available. This does not establish that every action succeeded.",listHint:"The last observed step can differ from the furthest observed step. Open sessions can still change. Closure runs every 15 minutes after more than 30 minutes of inactivity; a closed session is not necessarily a conversion.",listCaption:"Minimized agent sessions with last and furthest observed steps",lastStage:"Last step",maxStage:"Furthest step",state:"Session state",timeline:"Session timeline",noneObserved:"None recorded",closed:"Closed",open:"Open",inspect:"View journey",pagination:"Page {page} of {pages} \xB7 {total} agent sessions",firstSeen:"First seen (UTC)",reason:"Classification reason",observedSteps:"Observed steps",orderIds:"Linked Shopware order IDs",orderId:"Linked Shopware order ID",timelineHint:"Only minimized retained requests are listed. Missing steps and business outcomes remain unknown. Order IDs appear only when a Shopware order event was linked to this agent session.",noTimeline:"No request details remain for this session.",close:"Close",linkage:{exact:"Exact linkage",probabilistic:"Probabilistic linkage"},linkageHint:{exact:"Requests share an incoming context token or session cookie. This links observed requests; it does not identify a person.",probabilistic:"A shared provider key or request characteristics may group several visits. This journey is an estimate."},failureCode:{search_empty:"Search returned no results",product_unavailable:"Product unavailable",product_out_of_stock:"Product out of stock",http_4xx:"HTTP client error (4xx)",http_5xx:"HTTP server error (5xx)",forbidden:"Access denied (403)",rate_limited:"Rate limited (429)",checkout_form:"Checkout form error",cart_error:"Cart error",payment_error:"Payment error",abandoned_cart:"Abandoned after cart",abandoned_checkout:"Abandoned after checkout"},reasonCode:{no_evidence:"No identity evidence",ip_in_official_range:"Client IP belongs to an accepted operator range",identity_not_verified:"Declared identity without verified evidence",forward_confirmed_reverse_dns:"Reverse DNS confirmed by a forward lookup",valid_web_bot_auth:"Valid Web Bot Auth signature",bad_signature:"Signature could not be validated",unknown_key:"Verification key is unknown",expired:"Signature is outside its validity window",directory_unreachable:"Key directory unavailable",directory_retry_expired:"Key-directory retry window expired",invalid_replay_context:"Preserved context cannot be reverified"},unknownFailures:"{count} failure/evidence combinations have no sessions with a known outcome",errorRoutes:"Routes with HTTP errors",errorRoutesHint:"The 20 most frequent error routes among retained agent requests, including open sessions. These are request counts, not failure rates.",route:"Route",step:"Step",noErrorRoutes:"No HTTP error route is retained for this selection.",truncated:"The first 500 retained requests are shown. This timeline is incomplete.",knownOutcomes:"Known outcomes",outcome:{search:"Search result count",product:"Product availability",cart:"Cart validation",checkout:"Checkout validation",order:"Order attempt/result",http:"HTTP response",payment:"Payment evaluation"}}},"sw-privileges":{permissions:{agentenblick:{label:"Agentenblick"}}}};var g={agentenblick:{general:{title:"Agentenblick",menu:"KI-Agenten",description:"Erkennen Sie, welche Crawler und Agenten Ihren Shop besuchen und welche Nachweise ihre Identit\xE4t best\xE4tigen.",loading:"Beobachteten Traffic laden\u2026",empty:"F\xFCr diesen Zeitraum und Verkaufskanal wurden noch keine Anfragen beobachtet.",noAgents:"F\xFCr diese Auswahl wurde kein Crawler- oder Agenten-Traffic beobachtet.",scope:"Erfasst werden nur Anfragen, die Shopware erreichen. Agenten mit gew\xF6hnlicher Browserkennung k\xF6nnen unerkannt bleiben. Sitzungen sind vorl\xE4ufig; ein gemeinsamer Agentenschl\xFCssel kann mehrere Besuche zusammenfassen. Alle Zeitangaben sind in UTC."},actions:{refresh:"Aktualisieren",export:"Agenten als CSV exportieren",retry:"Erneut versuchen",previous:"Zur\xFCck",next:"Weiter",cancel:"Abbrechen"},tabs:{overview:"\xDCbersicht",agents:"Agenten",benchmark:"Benchmark",funnel:"Trichter",journeys:"Verl\xE4ufe",failures:"Fehler"},filters:{period:"Zeitraum",lastDays:"Letzte {days} Tage",channel:"Verkaufskanal",allChannels:"Alle Verkaufskan\xE4le"},metrics:{share:"Beobachteter Agentenanteil",shareHint:"Anteil an allen beobachteten Sitzungen, einschlie\xDFlich deklarierter Identit\xE4ten.",agentSessions:"Agentensitzungen",agentSessionsHint:"Crawler und Agenten, verifiziert oder deklariert.",agentRequests:"Agentenanfragen",requestsHint:"Von insgesamt {total} beobachteten Anfragen.",allSessions:"Alle Sitzungen",allSessionsHint:"Menschen, unbekannter Traffic und Agenten."},trend:{title:"Sitzungen im Zeitverlauf",all:"Alle Sitzungen",agents:"Agentensitzungen",accessible:"T\xE4gliche Anzahl aller Sitzungen und Agentensitzungen. Die genauen Werte stehen in der Tabelle darunter.",values:"Tageswerte anzeigen",date:"Datum (UTC)"},breakdown:{kinds:"Agententypen",evidence:"Identit\xE4tsnachweise",proofHint:"Jede Anfrage beh\xE4lt ihren eigenen Nachweis. Sitzungen verwenden den st\xE4rksten bei der Erfassung beobachteten Nachweis; f\xFCr sp\xE4tere Pr\xFCfungen gelten strengere Regeln."},table:{caption:"Beobachtete Agenten und ihre Identit\xE4tsnachweise",agent:"Agent / Betreiber",kind:"Typ",evidence:"Nachweis",requests:"Anfragen",sessions:"Sitzungen",lastSeen:"Zuletzt gesehen (UTC)",routes:"H\xE4ufigste Routen",showRoutes:"Routen anzeigen",pagination:"Seite {page} von {pages} \xB7 {total} Agenten",proofHint:"Eine deklarierte Identit\xE4t ist nicht verifiziert und belegt keine Identit\xE4tst\xE4uschung. Unterschiedliche Nachweise werden mit ihrer jeweiligen Anfrageanzahl angezeigt.",retentionHint:"Letzter Besuch und h\xE4ufigste Routen stammen aus den noch gespeicherten Anfragedetails. Eine generische Route bedeutet, dass Shopware keine aufgel\xF6ste Route bereitgestellt hat, etwa bei einem Cache-Treffer."},kind:{training_crawler:"Trainings-Crawler",search_crawler:"Suchmaschinen-Crawler",user_fetch:"Nutzerinitiierter Abruf",browsing_agent:"Browser-Agent",automation_unknown:"Unbekannte Automatisierung",human_or_unknown:"Mensch oder unbekannt",mixed:"Mehrere Typen"},evidence:{verified_signature:"Signatur verifiziert",verified_ip:"IP-Bereich verifiziert",verified_rdns:"Reverse-DNS verifiziert",declared_unverified:"Deklariert, nicht verifiziert",none:"Kein Identit\xE4tsnachweis",mixed:"Unterschiedliche Nachweise"},proxy:{title:"Konfiguration vertrauensw\xFCrdiger Proxys pr\xFCfen",description:"Die meisten aufgel\xF6sten Client-IP-Adressen im aktuellen Beobachtungsfenster geh\xF6ren zu bekannten CDN-Bereichen. Die IP-Pr\xFCfung verwendet m\xF6glicherweise die CDN-Adresse statt der Besucheradresse. Dies belegt keine Identit\xE4tst\xE4uschung.",remediation:"Tragen Sie in TRUSTED_PROXIES nur Ihre tats\xE4chlichen Proxy-Adressen ein und konfigurieren Sie die von Symfony akzeptierten Weiterleitungsheader. Der Proxy muss vom Besucher gesendete Header \xFCberschreiben. Pr\xFCfen Sie das Ergebnis von Request::getClientIp(). Die Warnung verschwindet in einem neuen Stundenfenster ohne CDN-Mehrheit."},ranges:{title:"IP-Bereichs\xE4nderungen zur Pr\xFCfung",description:"Diese offiziellen Listen haben sich um mehr als 30 % ge\xE4ndert. Die bisherigen Bereiche bleiben aktiv, bis ein Bearbeiter die vorgeschlagene Version best\xE4tigt.",current:"Aktuelle Bereiche",proposed:"Vorgeschlagene Bereiche",updated:"Heruntergeladen (UTC)",review:"\xC4nderung pr\xFCfen",editorRequired:"Bearbeitungsrecht erforderlich",confirmTitle:"IP-Bereiche f\xFCr {agent} best\xE4tigen",confirmText:"Die bisherigen {current} Bereiche durch die vorgeschlagenen {proposed} Bereiche ersetzen? Pr\xFCfen Sie vor der \xDCbernahme die Unterschiede und die offizielle Quelle des Betreibers.",source:"Offizielle Quelle \xF6ffnen",added:"Hinzugef\xFCgt ({count})",removed:"Entfernt ({count})",truncated:"Es werden jeweils die ersten 50 hinzugef\xFCgten und entfernten Bereiche angezeigt. Die vollst\xE4ndige Liste finden Sie in der offiziellen Quelle.",approve:"Diese Version best\xE4tigen",approved:"Die gepr\xFCfte Version der IP-Bereiche ist jetzt aktiv.",changed:"Die vorgeschlagenen Bereiche wurden w\xE4hrend Ihrer Pr\xFCfung ge\xE4ndert. Pr\xFCfen Sie die aktualisierte Version vor der Best\xE4tigung."},errors:{load:"Der Traffic f\xFCr diese Auswahl konnte nicht geladen werden. Bitte versuchen Sie es erneut.",export:"Die CSV-Datei konnte nicht heruntergeladen werden. Versuchen Sie es erneut oder pr\xFCfen Sie Ihr Leserecht.",approve:"Die IP-Bereiche konnten nicht best\xE4tigt werden. Pr\xFCfen Sie Ihr Bearbeitungsrecht und versuchen Sie es erneut.",permission:"Sie haben keine Berechtigung, Agentenblick anzuzeigen."},benchmark:{title:"Zum Shopware-Benchmark beitragen",description:"Die Teilnahme ist freiwillig und standardm\xE4\xDFig deaktiviert. Pr\xFCfen Sie unten die genaue Wochenmeldung, bevor Sie die automatische \xDCbermittlung an den Aithos-Benchmark aktivieren.",loading:"Lokale Benchmark-Einstellungen werden geladen\u2026",demo:"Demonstrationsmodus: Synthetische Daten werden getrennt von den Benchmark-Meldungen echter Shops verarbeitet.",offline:"Der Offline-Modus ist aktiv. Es werden keine Benchmark-Netzwerkanfragen gesendet. Vorschau und lokales Protokoll bleiben verf\xFCgbar.",withdrawalPending:"Die Teilnahme wurde lokal beendet. Die serverseitige L\xF6schung steht aus und wird bei verf\xFCgbarem Netzwerkzugang erneut versucht.",privacy:{aggregate:"\xDCbermittelt werden nur abgeschlossene ISO-Wochen: auf 0,1 Prozentpunkt gerundete Anteile, grobe Segmente und eine zuf\xE4llige Installationskennung. Verkaufskanal-Kennungen werden in der Meldung pseudonymisiert.",excluded:"Keine Roh-IP, Besucher- oder Sitzungskennung, Shop-Domain, Anfrageinhalte, URL-Parameter, Bestellkennung oder einzelne Anfrage wird \xFCbermittelt.",threshold:"Bei einem Nenner unter 10 Sitzungen wird die Shop-Kennzahl unterdr\xFCckt. Eine ver\xF6ffentlichte Benchmark-Kennzahl ben\xF6tigt mindestens 5 berechtigte Installationen; mehrere Kan\xE4le eines Shops z\xE4hlen als ein Teilnehmer.",retention:"Meldungen werden 13 Monate aufbewahrt. Ein Widerruf beendet k\xFCnftige Meldungen und fordert die L\xF6schung gespeicherter Meldungen dieser Installation an.",journey:"Version 2 erg\xE4nzt Anteile unabh\xE4ngig beobachteter Schritte und benannter Fehler, getrennt nach verifizierten und deklarierten Agenten. Fehlerquoten ber\xFCcksichtigen nur Sitzungen mit bekanntem Ergebnis; fehlende Schritte werden nicht erg\xE4nzt."},methodology:"\xD6ffentliche Methodik und Datenschutzregeln lesen",installation:"Zuf\xE4llige Installations-ID",consentAt:"Einwilligung erfasst (UTC)",consent:"Ich habe die Datenvorschau und Methodik gepr\xFCft. Ich stimme der \xDCbermittlung der beschriebenen w\xF6chentlichen Verkehrs-, Verlaufs- und Fehleraggregate an den Aithos-Benchmark gem\xE4\xDF Einwilligungsversion 2 zu. Einzelne Anfragen, Sitzungen oder Bestellungen werden nicht gesendet. Ich kann jederzeit widerrufen.",enable:"W\xF6chentliche Teilnahme aktivieren",send:"Ausstehende Wochenaggregate senden",disable:"Teilnahme widerrufen",editorRequired:"Zum Aktivieren, Senden und Widerrufen ist die Bearbeitungsberechtigung erforderlich.",previewTitle:"Vorschau der exakten Meldung",previewHint:"Diese Vorschau wird lokal erzeugt. Beim \xD6ffnen oder Aktualisieren werden keine Daten an den Benchmark gesendet.",previewPrivacy:"Das JSON zeigt die exakten Meldungsbytes einschlie\xDFlich unterdr\xFCckter Werte. Die hier angezeigten lokalen Verkaufskanal-Kennungen werden nicht in dieser Form \xFCbermittelt.",period:"ISO-Woche",noPreview:"Es liegt noch keine abgeschlossene Woche f\xFCr eine Meldung vor.",channel:"Lokaler Verkaufskanal",exactJson:"Exakte JSON-Meldung",refreshLocal:"Lokale Vorschau und Protokoll aktualisieren",historyTitle:"\xDCbermittlungsprotokoll",historyHint:"Pr\xFCfen Sie die genaue Meldung und ihren Hash f\xFCr jede lokale \xDCbermittlung. Alle Zeitangaben sind in UTC.",noHistory:"Es wurden noch keine Meldungen vorbereitet oder gesendet.",status:"Status",sentAt:"Gesendet (UTC)",inspect:"Meldung ansehen",comparisonTitle:"Mit einer ver\xF6ffentlichten Kohorte vergleichen",comparisonHint:"Die Kennzahlen stammen aus vergleichbaren, disjunkten Kohorten. Der Median ist ein Anteil je Installation; die Quartile zeigen die mittlere H\xE4lfte der ver\xF6ffentlichten Werte.",comparisonNetwork:"Der Abruf kontaktiert shopware.aithos.app. Daf\xFCr muss die Teilnahme aktiviert und der Offline-Modus ausgeschaltet sein.",compare:"Ver\xF6ffentlichten Benchmark abrufen",openDashboard:"Forschungsdashboard \xF6ffnen",insufficient:"Es ist keine vergleichbare Kennzahl verf\xFCgbar. Sie ist m\xF6glicherweise unver\xF6ffentlicht, nicht erreichbar oder unterschreitet die Schwelle von f\xFCnf Installationen.",cohort:"Ver\xF6ffentlichte Kohorte",median:"Agentenanteil: Median",quartiles:"25.\u201375. Perzentil",installations:"Berechtigte Installationen",withdrawTitle:"Benchmark-Teilnahme widerrufen",withdrawText:"K\xFCnftige Meldungen stoppen und die L\xF6schung gespeicherter Meldungen dieser Installation anfordern? Die lokale Verkehrsmessung l\xE4uft weiter.",withdrawOffline:"Im Offline-Modus kann die L\xF6schanfrage jetzt nicht gesendet werden. Sie bleibt ausstehend, bis Netzwerkzugang verf\xFCgbar ist.",confirmWithdrawal:"Teilnahme beenden und L\xF6schung anfordern",state:{withdrawal_pending:"L\xF6schung ausstehend",offline:"Offline",ready:"Teilnahme aktiviert",disabled:"Teilnahme deaktiviert",registration_pending:"Registrierung ausstehend",test_identity_conflict:"Echte Identit\xE4t im Test gesperrt",consent_update_required:"Neue Einwilligung erforderlich"},submission:{draft:"Lokaler Entwurf",pending:"\xDCbermittlung ausstehend",sent:"Gesendet",failed:"Fehlgeschlagen; Wiederholung ausstehend",unknown:"Unbekannter Status",rejected:"Abgelehnt; Meldung pr\xFCfen",expired:"Abgelaufen; au\xDFerhalb des \xDCbermittlungsfensters von 13 Wochen",withdrawn:"Zur\xFCckgezogen; im lokalen Protokoll aufbewahrt"},success:{enable:"Die w\xF6chentliche Benchmark-Teilnahme ist aktiviert.",disable:"Die Teilnahme wurde beendet. Pr\xFCfen Sie den Status der L\xF6schanfrage.",send:"Ausstehende Aggregate wurden verarbeitet. Pr\xFCfen Sie die einzelnen Ergebnisse im Protokoll."},error:{load:"Die lokalen Benchmark-Einstellungen konnten nicht geladen werden. Bitte erneut versuchen.",update:"Die Aktion konnte nicht best\xE4tigt werden. Aktualisieren Sie den lokalen Status und pr\xFCfen Sie Ihre Bearbeitungsberechtigung.",compare:"Der Benchmark konnte nicht abgerufen werden. Bitte sp\xE4ter erneut versuchen.",send:"Die \xDCbermittlung konnte nicht abgeschlossen werden. Pr\xFCfen Sie ihren Status im lokalen Protokoll."},localShare:"Dieser Shop: Agentenanteil je Kanal",comparisonReason:{demo_dashboard_authentication_required:"Demonstrations-Benchmarks erfordern eine Partneranmeldung. \xD6ffnen Sie das Forschungsdashboard f\xFCr synthetische Kohorten.",offline:"Im Offline-Modus kann der Benchmark nicht abgerufen werden.",disabled:"Aktivieren Sie die Teilnahme, bevor Sie einen Vergleich abrufen.",withdrawal_pending:"W\xE4hrend der ausstehenden L\xF6schanfrage ist kein Vergleich verf\xFCgbar."},identityConflict:"Diese Testumgebung enth\xE4lt eine bestehende echte Installationsidentit\xE4t. Aktivierung, Meldungen, Vergleiche und serverseitige L\xF6schung sind gesperrt. Verwenden Sie eine separate Demonstrationsidentit\xE4t und nicht die Produktionsidentit\xE4t einer geklonten Datenbank.",consentUpdate:"Der Benchmark enth\xE4lt jetzt Verlaufs- und Fehleranteile. Neue \xDCbermittlungen sind bis zur ausdr\xFCcklichen Zustimmung zu Einwilligungsversion 2 pausiert. Bereits festgeschriebene Dokumente bleiben unver\xE4ndert; ein Widerruf ist weiterhin m\xF6glich.",renewConsent:"Version 2 akzeptieren und Teilnahme fortsetzen"},journey:{description:"Lokale Verl\xE4ufe beobachteter Crawler und Agenten. Keine einzelnen Anfragen oder Sitzungen menschlicher Besucher werden angezeigt. Alle Zeiten sind UTC.",unknown:"Unbekannt",unknownRoute:"Nicht aufgel\xF6ste Route",loadFailed:"Die Verl\xE4ufe konnten f\xFCr diese Auswahl nicht geladen werden. Bitte erneut versuchen.",detailFailed:"Dieser Verlauf ist nicht verf\xFCgbar, liegt au\xDFerhalb des gew\xE4hlten Zeitraums oder Verkaufskanals oder wurde bereits gel\xF6scht.",funnelHint:"Jede Stufe z\xE4hlt geschlossene Agentensitzungen, in denen dieser Schritt tats\xE4chlich beobachtet wurde. \xDCbersprungene oder unbekannte Schritte werden nicht erg\xE4nzt. Ein Seitenaufruf beweist keinen erfolgreichen Abschluss.",sessionCounts:"{closed} geschlossene Sitzungen \xB7 {open} offene Sitzungen",groupSessions:"{count} Sitzungen mit diesem Nachweis",empty:"F\xFCr diese Auswahl sind keine Agentenverl\xE4ufe verf\xFCgbar.",proofGroup:{verified:"Verifizierte Agenten",declared:"Deklarierte Agenten",other:"Andere Nachweise"},proofHint:{verified:"Identit\xE4t durch Signatur, IP-Bereich oder Reverse-DNS best\xE4tigt. Die Nachweisarten werden getrennt angezeigt.",declared:"Der Agent gibt eine bisher nicht verifizierte Identit\xE4t an. Das beweist keinen Identit\xE4tsmissbrauch.",other:"Keine best\xE4tigte Betreiberidentit\xE4t. Diese Sitzungen z\xE4hlen nicht zu den verifizierten Agenten."},stage:{discover:"Entdeckung",browse:"St\xF6bern",search:"Suche",product:"Produkt",cart:"Warenkorb",checkout:"Checkout",order:"Bestellung aufgegeben"},failure:"Beobachteter Fehler",affected:"Betroffene Sitzungen",eligible:"Sitzungen mit bekanntem Ergebnis",rate:"Anteil",allProofs:"Alle Agentennachweise",failuresCaption:"Benannte Fehler und die zugeh\xF6rigen auswertbaren Sitzungen",failuresHint:"Anteile ber\xFCcksichtigen nur Sitzungen mit bekanntem Ergebnis f\xFCr den jeweiligen Vorgang. Unbekannte Ergebnisse, etwa Cache-Treffer ohne entsprechendes Ereignis, bleiben ausgeschlossen. \u201E\u2014\u201C bedeutet kein g\xFCltiger Nenner, niemals Erfolg. Eine Sitzung kann mehrere Fehler enthalten.",noFailures:"Keine benannten Fehler verf\xFCgbar. Daraus folgt nicht, dass alle Vorg\xE4nge erfolgreich waren.",listHint:"Der zuletzt beobachtete Schritt kann von der h\xF6chsten beobachteten Stufe abweichen. Offene Sitzungen k\xF6nnen sich noch \xE4ndern. Nach mehr als 30 Minuten Inaktivit\xE4t werden Sitzungen alle 15 Minuten geschlossen; eine geschlossene Sitzung bedeutet keinen Kaufabschluss.",listCaption:"Minimierte Agentensitzungen mit letztem Schritt und h\xF6chster beobachteter Stufe",lastStage:"Letzter Schritt",maxStage:"H\xF6chste Stufe",state:"Sitzungsstatus",timeline:"Sitzungsverlauf",noneObserved:"Keine erfasst",closed:"Geschlossen",open:"Offen",inspect:"Verlauf ansehen",pagination:"Seite {page} von {pages} \xB7 {total} Agentensitzungen",firstSeen:"Erstmals gesehen (UTC)",reason:"Klassifizierungsgrund",observedSteps:"Beobachtete Schritte",orderIds:"Verkn\xFCpfte Shopware-Bestell-IDs",orderId:"Verkn\xFCpfte Shopware-Bestell-ID",timelineHint:"Es werden nur gespeicherte, minimierte Anfragen angezeigt. Fehlende Schritte und Gesch\xE4ftsergebnisse bleiben unbekannt. Bestell-IDs erscheinen nur, wenn ein Shopware-Bestellereignis dieser Agentensitzung zugeordnet wurde.",noTimeline:"F\xFCr diese Sitzung sind keine Anfragedetails mehr gespeichert.",close:"Schlie\xDFen",linkage:{exact:"Exakte Zuordnung",probabilistic:"Wahrscheinliche Zuordnung"},linkageHint:{exact:"Die Anfragen verwenden denselben eingehenden Kontext-Token oder dasselbe Sitzungscookie. Dies verkn\xFCpft Anfragen und identifiziert keine Person.",probabilistic:"Ein geteilter Anbieterschl\xFCssel oder Anfragemerkmale k\xF6nnen mehrere Besuche zusammenfassen. Dieser Verlauf ist eine Sch\xE4tzung."},failureCode:{search_empty:"Suche ohne Treffer",product_unavailable:"Produkt nicht verf\xFCgbar",product_out_of_stock:"Produkt nicht auf Lager",http_4xx:"HTTP-Clientfehler (4xx)",http_5xx:"HTTP-Serverfehler (5xx)",forbidden:"Zugriff verweigert (403)",rate_limited:"Anfragelimit erreicht (429)",checkout_form:"Formularfehler im Checkout",cart_error:"Warenkorbfehler",payment_error:"Zahlungsfehler",abandoned_cart:"Abbruch nach Warenkorb",abandoned_checkout:"Abbruch nach Checkout"},reasonCode:{no_evidence:"Kein Identit\xE4tsnachweis",ip_in_official_range:"Client-IP geh\xF6rt zu einem akzeptierten Betreiberbereich",identity_not_verified:"Deklarierte Identit\xE4t ohne verifizierten Nachweis",forward_confirmed_reverse_dns:"Reverse-DNS durch Vorw\xE4rtsaufl\xF6sung best\xE4tigt",valid_web_bot_auth:"G\xFCltige Web-Bot-Auth-Signatur",bad_signature:"Signatur konnte nicht validiert werden",unknown_key:"Verifizierungsschl\xFCssel unbekannt",expired:"Signatur au\xDFerhalb ihres G\xFCltigkeitszeitraums",directory_unreachable:"Schl\xFCsselverzeichnis nicht erreichbar",directory_retry_expired:"Wiederholungsfrist f\xFCr Schl\xFCsselverzeichnis abgelaufen",invalid_replay_context:"Gespeicherter Kontext kann nicht erneut verifiziert werden"},unknownFailures:"F\xFCr {count} Fehler-/Nachweiskombinationen gibt es keine Sitzungen mit bekanntem Ergebnis",errorRoutes:"Routen mit HTTP-Fehlern",errorRoutesHint:"Die 20 h\xE4ufigsten Fehlerrouten gespeicherter Agentenanfragen, einschlie\xDFlich offener Sitzungen. Angezeigt werden Anfragen, keine Fehlerquoten.",route:"Route",step:"Schritt",noErrorRoutes:"F\xFCr diese Auswahl sind keine HTTP-Fehlerrouten gespeichert.",truncated:"Die ersten 500 gespeicherten Anfragen werden angezeigt. Dieser Verlauf ist unvollst\xE4ndig.",knownOutcomes:"Bekannte Ergebnisse",outcome:{search:"Anzahl der Suchtreffer",product:"Produktverf\xFCgbarkeit",cart:"Warenkorbpr\xFCfung",checkout:"Checkout-Pr\xFCfung",order:"Bestellversuch/-ergebnis",http:"HTTP-Antwort",payment:"Zahlungspr\xFCfung"}}},"sw-privileges":{permissions:{agentenblick:{label:"Agentenblick"}}}};Shopware.Service().register("agentenblickApiService",()=>new a(Shopware.Application.getContainer("init").httpClient,Shopware.Service("loginService")));Shopware.Module.register("aithos-agentenblick",{type:"plugin",routePrefixName:"agentenblick",routePrefixPath:"agentenblick",name:"Agentenblick",title:"agentenblick.general.title",description:"agentenblick.general.description",color:"#147d75",icon:"regular-chart-pie",snippets:{"en-GB":d,"de-DE":g},routes:{index:{component:"agentenblick-index",path:"index",meta:{privilege:"agentenblick.viewer"}}},navigation:[{id:"agentenblick",parent:"sw-marketing",path:"agentenblick.index",label:"agentenblick.general.menu",color:"#147d75",icon:"regular-chart-pie",position:70,privilege:"agentenblick.viewer"}]});})();
