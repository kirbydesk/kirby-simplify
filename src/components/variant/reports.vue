<template>
  <section class="simplify-reports-tab">
    <k-empty
      v-if="
        reports.length === 0 &&
        runningJobs.length === 0 &&
        pendingJobs.length === 0
      "
      icon="stats"
      layout="cards"
    >
      {{ $t("simplify.reports.empty") }}
    </k-empty>

    <div v-else class="k-field">
      <header class="k-field-header">
        <label class="k-label k-field-label">
          <span class="k-label-text">
            {{ $t("simplify.reports.list.label") }}
          </span>
        </label>
        <div style="display: flex; gap: var(--spacing-2); align-items: center">
          <span data-theme="positive" class="k-counter k-field-counter">
            <span>{{ filteredReports.length }}</span>
          </span>
          <k-input
            v-if="searching"
            ref="search"
            v-model="searchQuery"
            :placeholder="$t('search') + ' …'"
            type="text"
            class="k-models-section-search k-input"
            style="
              min-height: auto;
              height: var(--height-xs);
              margin-bottom: 0;
              width: 200px;
            "
            @keydown.esc="toggleSearch(true)"
          />
          <button
            data-has-icon="true"
            data-size="xs"
            data-variant="filled"
            type="button"
            class="k-button"
            :title="searching ? $t('close') : $t('filter')"
            @click="toggleSearch"
          >
            <span class="k-button-icon">
              <svg
                aria-hidden="true"
                :data-type="searching ? 'cancel' : 'filter'"
                class="k-icon"
              >
                <use
                  :xlink:href="searching ? '#icon-cancel' : '#icon-filter'"
                ></use>
              </svg>
            </span>
          </button>
          <button
            data-has-icon="true"
            data-size="xs"
            data-variant="filled"
            type="button"
            class="k-button"
            :title="$t('settings')"
            @click="$refs.reportsSettingsDropdown.toggle()"
          >
            <span class="k-button-icon">
              <svg aria-hidden="true" data-type="cog" class="k-icon">
                <use xlink:href="#icon-cog"></use>
              </svg>
            </span>
          </button>
        </div>
      </header>
      <k-dropdown-content
        ref="reportsSettingsDropdown"
        :options="reportsSettingsDropdownOptions"
        align-x="end"
      />

      <k-empty v-if="filteredReports.length === 0" icon="stats" layout="cards">
        {{ $t("simplify.reports.empty") }}
      </k-empty>

      <template v-else>
        <k-table
          :columns="columns"
          :rows="paginatedReports"
          :index="index"
          @header="onHeader"
        >
          <template #header="{ columnIndex, label }">
            <span class="simplify-table-header-sortable">
              {{ label }}
              <k-icon
                v-if="columnIndex === sortBy"
                :type="sortDirection === 'asc' ? 'angle-up' : 'angle-down'"
              />
            </span>
          </template>
          <template #options="{ row }">
            <k-options-dropdown :options="getReportOptions(row)" />
          </template>
        </k-table>

        <footer class="k-bar k-collection-footer">
          <k-pagination
            v-bind="pagination"
            :details="true"
            :total="filteredReports.length"
            @paginate="onPaginate"
          />
        </footer>
      </template>
    </div>
  </section>
</template>

<script>
export default {
  name: "ReportsTab",
  props: {
    variantCode: {
      type: String,
      required: true,
    },
    allPages: {
      type: Array,
      required: true,
    },
    queueCount: {
      type: Number,
      default: 0,
    },
    cacheCount: {
      type: Number,
      default: 0,
    },
    reportsCount: {
      type: Number,
      default: 0,
    },
  },
  data() {
    return {
      reports: [],
      runningJobs: [],
      pendingJobs: [],
      polling: null,
      loading: false,
      searching: false,
      searchQuery: "",
      sortBy: "date",
      sortDirection: "desc",
      pagination: {
        page: 1,
        limit: 50,
      },
    };
  },
  computed: {
    reportsSettingsDropdownOptions() {
      const self = this;
      return [
        {
          icon: "trash",
          text: this.$t("simplify.queue.clear"),
          disabled: this.queueCount === 0,
          click() {
            self.$emit("clear-queue");
          },
        },
        {
          icon: "trash",
          text: this.$t("simplify.cache.clear"),
          disabled: this.cacheCount === 0,
          click() {
            self.$emit("clear-cache");
          },
        },
        "-",
        {
          icon: "trash",
          text: this.$t("simplify.reports.clear"),
          disabled: this.reportsCount === 0,
          click() {
            self.confirmClearReports();
          },
        },
      ];
    },
    columns() {
      return {
        page: {
          label: this.$t("simplify.reports.page"),
          type: "html",
          width: "1fr",
          mobile: true,
        },
        date: {
          label: this.$t("simplify.reports.date"),
          type: "html",
          width: "200px",
        },
        status: {
          label: this.$t("simplify.reports.status"),
          type: "html",
          width: "60px",
        },
        options: {
          type: "options",
          width: "auto",
        },
      };
    },
    filteredReports() {
      // Combine jobs and reports into unified list
      const allItems = [];

      // Add running jobs
      this.runningJobs.forEach((job) => {
        allItems.push({
          type: "job",
          status: "processing",
          timestamp: job.createdAt,
          jobData: job,
          message: job.pageTitle, // For search
        });
      });

      // Add pending jobs
      this.pendingJobs.forEach((job) => {
        allItems.push({
          type: "job",
          status: "pending",
          timestamp: job.createdAt,
          jobData: job,
          message: job.pageTitle, // For search
        });
      });

      // Add completed reports
      this.reports.forEach((report) => {
        allItems.push({
          type: "report",
          ...report,
          message: report.pageTitle || report.message || "", // Add message for search compatibility
        });
      });

      // Apply search filter
      if (!this.searchQuery) {
        return allItems;
      }

      const query = this.searchQuery.toLowerCase();
      return allItems.filter((item) => {
        const searchText = item.message || item.pageTitle || "";
        return searchText.toLowerCase().includes(query);
      });
    },
    sortedReports() {
      const reports = [...this.filteredReports];

      if (!this.sortBy) {
        return reports;
      }

      return reports.sort((a, b) => {
        let aVal = a.timestamp;
        let bVal = b.timestamp;

        // Always sort by timestamp for consistent order
        aVal = new Date(aVal).getTime();
        bVal = new Date(bVal).getTime();

        if (this.sortDirection === "asc") {
          return aVal > bVal ? 1 : -1;
        } else {
          return aVal < bVal ? 1 : -1;
        }
      });
    },
    paginatedReports() {
      const start = this.index - 1;
      const end = this.pagination.limit * this.pagination.page;
      return this.sortedReports.slice(start, end).map((item) => {
        // Handle jobs differently from reports
        if (item.type === "job") {
          const job = item.jobData;
          const page = this.allPages?.find((p) => p.title === job.pageTitle);

          return {
            itemType: "job",
            page: this.formatPageWithStatus(
              job.pageTitle,
              null,
              page?.id,
              this.variantCode
            ),
            date: this.formatDate(job.createdAt),
            status: this.formatStatus(item.status),
            // Store for options menu
            pageName: job.pageTitle,
            pageId: page?.id,
            pageUuid: page?.uuid,
            pageMode: page?.mode,
            rawTitle: page?.title,
            timestamp: job.createdAt,
            jobStatus: item.status,
            jobId: job.id,
          };
        }

        // Handle completed reports
        const report = item;

        // Use new SQLite data structure if available
        const action = report.action || "";
        const status = report.status || "";
        const pageName = report.pageTitle || "";
        const pageUuid = report.pageUuid || null;
        const pageId = report.pageId || report.page_id || null;
        const languageCode =
          report.languageCode || report.language_code || this.variantCode;
        const tokens = report.tokens || 0;
        const error = report.error || "";

        // Find the actual page object by UUID first, then pageId, fallback to title
        let page = null;

        if (pageUuid) {
          // Normalize UUID - remove page:// prefix if present
          const normalizedUuid = pageUuid.replace(/^page:\/\//, "");
          page = this.allPages?.find((p) => {
            const pageNormalizedUuid = p.uuid
              ? p.uuid.replace(/^page:\/\//, "")
              : null;
            return pageNormalizedUuid === normalizedUuid;
          });
        }
        // Try pageId if UUID search failed
        if (!page && pageId) {
          page = this.allPages?.find((p) => p.id === pageId);
        }
        // Fallback to title if still not found
        if (!page) {
          page = this.allPages?.find((p) => p.title === pageName);
        }

        // Use pageId from report or from found page
        const finalPageId = pageId || page?.id;

        return {
          itemType: "report",
          page: this.formatPageWithStatus(
            pageName,
            error,
            finalPageId,
            languageCode
          ),
          date: this.formatDate(report.timestamp),
          status: this.formatStatus(status),
          tokens: this.formatTokens(tokens),
          // Store for options menu
          pageName: pageName,
          pageId: finalPageId,
          pageUuid: page?.uuid,
          pageMode: page?.mode,
          rawTitle: page?.title,
          timestamp: report.timestamp,
          timestampRaw: report.timestampRaw, // Raw Unix timestamp for delete operations
          jobStatus: status,
          error: error,
        };
      });
    },
    index() {
      return (this.pagination.page - 1) * this.pagination.limit + 1;
    },
  },
  async mounted() {
    await this.loadReports();
    await this.loadJobs();
    this.startPolling();
  },
  beforeDestroy() {
    this.stopPolling();
  },
  methods: {
    toggleSearch(closeOnly = false) {
      if (closeOnly) {
        this.searching = false;
        this.searchQuery = "";
        return;
      }

      this.searching = !this.searching;

      if (!this.searching) {
        this.searchQuery = "";
      } else {
        this.$nextTick(() => {
          this.$refs.search?.focus();
        });
      }
    },
    async loadReports() {
      this.loading = true;
      try {
        const response = await this.$api.get("simplify/reports", {
          variantCode: this.variantCode,
        });

        if (response.success) {
          this.reports = response.reports || [];
        }
      } catch (error) {
        console.error("Failed to load reports:", error);
        this.$panel.notification.error(this.$t("simplify.reports.loadError"));
      } finally {
        this.loading = false;
      }
    },
    async loadJobs() {
      try {
        const response = await this.$api.get("simplify/jobs", {
          variantCode: this.variantCode,
        });

        if (response.success) {
          this.runningJobs = response.jobs.filter(
            (j) => j.status === "processing"
          );
          this.pendingJobs = response.jobs.filter(
            (j) => j.status === "pending"
          );
        }
      } catch (error) {
        console.error("Failed to load jobs:", error);
      }
    },
    startPolling() {
      // Track previous job count to detect when jobs finish
      let previousJobCount = this.runningJobs.length + this.pendingJobs.length;

      this.polling = setInterval(async () => {
        // Always load jobs to check for new ones
        await this.loadJobs();

        // Calculate new job count
        const currentJobCount =
          this.runningJobs.length + this.pendingJobs.length;

        // Reload reports if job count decreased (a job completed)
        if (currentJobCount < previousJobCount) {
          await this.loadReports();
        }

        // Update previous count for next iteration
        previousJobCount = currentJobCount;
      }, 2000); // Poll every 2 seconds
    },
    stopPolling() {
      if (this.polling) {
        clearInterval(this.polling);
        this.polling = null;
      }
    },
    async toggleSearch(close = false) {
      if (close && this.searchQuery) {
        this.searchQuery = "";
        return;
      }

      this.searching = !this.searching;

      if (this.searching) {
        await this.$nextTick();
        this.$refs.search?.focus();
      } else {
        this.searchQuery = "";
      }
    },
    onHeader({ columnIndex }) {
      if (this.sortBy === columnIndex) {
        // Toggle between asc and desc
        this.sortDirection = this.sortDirection === "asc" ? "desc" : "asc";
      } else {
        this.sortBy = columnIndex;
        this.sortDirection = "asc";
      }
      this.pagination.page = 1;
    },
    onPaginate({ page }) {
      this.pagination.page = page;
    },
    formatPageWithStatus(
      pageName,
      error = null,
      pageId = null,
      languageCode = null
    ) {
      // Find the page in allPages by pageId (more reliable than title)
      // Fallback to title search if pageId not available
      let page = null;
      if (pageId) {
        page = this.allPages?.find((p) => p.id === pageId);
      }
      if (!page) {
        page = this.allPages?.find((p) => p.title === pageName);
      }

      // Use the correct title from allPages if page was found
      const displayTitle = page?.title || pageName;

      // Default status for pages that don't exist (e.g. dummy entries)
      const status = page?.status || "draft";
      const statusTheme =
        {
          draft: "negative-icon",
          unlisted: "info-icon",
          listed: "positive-icon",
        }[status] || "info-icon";

      const statusText = this.$t("page.status");
      const statusLabel =
        {
          draft: this.$t("page.status.draft"),
          unlisted: this.$t("page.status.unlisted"),
          listed: this.$t("page.status.listed"),
        }[status] || this.$t("page.status.draft");

      // Build error display if error exists
      const errorHtml = error
        ? `<div class="report-error">${this.escapeHtml(error)}</div>`
        : "";

      // Build page link like in stats table
      let pageDisplay = displayTitle;
      if (pageId && languageCode) {
        const pageIdEncoded = pageId.replace(/\//g, "+");
        const url = `pages/${pageIdEncoded}?language=${languageCode}`;
        pageDisplay = `<a href="${url}" style="color: var(--color-blue-600); text-decoration: underline;">${displayTitle}</a>`;
      }

      return `<span style="display:flex;align-items:center;gap:var(--spacing-2);">
        <button data-has-icon="true" aria-label="${statusText}: ${statusLabel}" data-size="xs" data-theme="${statusTheme}" title="${statusText}: ${statusLabel}" type="button" class="k-button" style="--icon-size: 15px; flex-shrink: 0;">
          <span class="k-button-icon">
            <svg aria-hidden="true" data-type="status-${status}" class="k-icon">
              <use xlink:href="#icon-status-${status}"></use>
            </svg>
          </span>
        </button>
        <div style="flex:1;">
          <div>${pageDisplay}</div>
          ${errorHtml}
        </div>
      </span>`;
    },
    formatDate(timestamp) {
      // Format: "2025-10-28 08:49:15" → "<span class="date" style="white-space: nowrap;">28.10.2025, 08:49:15</span>"
      const date = new Date(timestamp);
      const formatted = date.toLocaleString(this.$panel.translation.code, {
        day: "2-digit",
        month: "2-digit",
        year: "numeric",
        hour: "2-digit",
        minute: "2-digit",
        second: "2-digit",
      });
      return `<span class="date" style="white-space: nowrap;">${formatted}</span>`;
    },
    formatMode(action) {
      // Get first letter (uppercase) from translated text, centered
      const modeClass =
        action === "MANUAL"
          ? "simplify-variant-mode--manual"
          : "simplify-variant-mode--auto";
      const translationKey =
        action === "MANUAL"
          ? "simplify.pages.mode.manual"
          : "simplify.pages.mode.auto";
      const text = this.$t(translationKey) || action;
      const letter = text.charAt(0).toUpperCase();

      return `<div style="display: flex; justify-content: center;"><span class="simplify-variant-mode ${modeClass}" title="${text}">${letter}</span></div>`;
    },
    formatStatus(status) {
      // Unified status formatting for both reports and jobs - only icon with text in title
      const statusMap = {
        // Reports
        SUCCESS: {
          icon: "check",
          theme: "positive",
          text: this.$t("simplify.reports.status.success"),
        },
        FAILED: {
          icon: "cancel",
          theme: "negative",
          text: this.$t("simplify.reports.status.failed"),
        },
        // Jobs
        pending: {
          icon: "clock",
          theme: "info",
          text: this.$t("simplify.reports.status.pending"),
        },
        processing: {
          icon: "loader",
          theme: "notice",
          text: this.$t("simplify.reports.status.running"),
        },
        // Future status options
        CANCELED: {
          icon: "cancel",
          theme: "passive",
          text: this.$t("simplify.reports.status.canceled"),
        },
        TIMEOUT: {
          icon: "timeout",
          theme: "negative",
          text: this.$t("simplify.reports.status.timeout"),
        },
        timeout: {
          icon: "timeout",
          theme: "negative",
          text: this.$t("simplify.reports.status.timeout"),
        },
      };

      const statusInfo = statusMap[status] || statusMap.FAILED;

      // Only icon, text in title attribute, centered
      return `<div style="display: flex; justify-content: center; align-items: center;">
        <span title="${statusInfo.text}" style="display: inline-flex; align-items: center; cursor: default;">
          <span data-has-icon="true" data-theme="${statusInfo.theme}" class="k-button" style="display: flex; align-items: center; justify-content: center; pointer-events: none;">
            <span class="k-button-icon">
              <svg aria-hidden="true" data-type="${statusInfo.icon}" class="k-icon">
                <use xlink:href="#icon-${statusInfo.icon}"></use>
              </svg>
            </span>
          </span>
        </span>
      </div>`;
    },
    formatJobMode(job, pageMode) {
      // Determine mode based on isManual flag
      const action = job.isManual === false ? "AUTO" : "MANUAL";
      // Reuse formatMode for consistent display
      return this.formatMode(action);
    },
    formatTokens(tokens) {
      // Format number with dot as thousands separator (1.000)
      const num = parseInt(tokens, 10);
      if (isNaN(num) || num === 0) {
        return tokens; // Return as-is if not a number or zero
      }
      return num.toLocaleString(this.$panel.translation.code);
    },
    getReportOptions(row) {
      const status = row.jobStatus;
      // Handle both uppercase (from reports) and lowercase (from jobs)
      const isPending = status === "PENDING" || status === "pending";
      const isRunning = status === "RUNNING" || status === "processing";
      const isFailed =
        status === "FAILED" ||
        status === "failed" ||
        status === "TIMEOUT" ||
        status === "timeout";
      const isCompleted = status === "SUCCESS";
      const isCanceled = status === "CANCELED";

      if (!row.pageId) {
        // Page not found - only show delete option for log entries
        return [
          {
            icon: "trash",
            text: this.$t("simplify.reports.delete"),
            click: () => {
              this.deleteReportEntry(row.timestampRaw);
            },
          },
        ];
      }

      const options = [];

      // Pending Job: Open page, Cancel
      if (isPending && row.itemType === "job") {
        options.push({
          icon: "open",
          text: this.$t("open"),
          click: () => {
            window.open(`/${this.variantCode}/${row.pageId}`, "_blank");
          },
        });
        options.push("-");
        options.push({
          icon: "cancel",
          text: this.$t("cancel"),
          click: () => {
            this.cancelJob(row.jobId);
          },
        });
      }

      // Processing Job: Open page, Cancel
      if (isRunning && row.itemType === "job") {
        options.push({
          icon: "open",
          text: this.$t("open"),
          click: () => {
            window.open(`/${this.variantCode}/${row.pageId}`, "_blank");
          },
        });
        options.push("-");
        options.push({
          icon: "cancel",
          text: this.$t("cancel"),
          click: () => {
            this.cancelJob(row.jobId);
          },
        });
      }

      // Failed Job: Retry, Delete job
      if (isFailed && row.itemType === "job") {
        options.push({
          icon: "refresh",
          text: this.$t("simplify.reports.retry"),
          click: () => {
            this.retryJob(row);
          },
        });
        options.push("-");
        options.push({
          icon: "trash",
          text: this.$t("simplify.reports.delete"),
          click: () => {
            this.deleteReportEntry(row.timestampRaw);
          },
        });
      }

      // Failed Report: Preview, Retry, Delete entry
      if (isFailed && row.itemType === "report") {
        options.push({
          icon: "open",
          text: this.$t("open"),
          click: () => {
            window.open(`/${this.variantCode}/${row.pageId}`, "_blank");
          },
        });
        options.push("-");
        options.push({
          icon: "refresh",
          text: this.$t("simplify.reports.retry"),
          click: () => {
            this.retryJob(row);
          },
        });
        options.push("-");
        options.push({
          icon: "trash",
          text: this.$t("simplify.reports.delete"),
          click: () => {
            this.deleteReportEntry(row.timestampRaw);
          },
        });
      }

      // Canceled Report: Preview, Retry, Delete entry
      if (isCanceled && row.itemType === "report") {
        options.push({
          icon: "open",
          text: this.$t("open"),
          click: () => {
            window.open(`/${this.variantCode}/${row.pageId}`, "_blank");
          },
        });
        options.push("-");
        options.push({
          icon: "refresh",
          text: this.$t("simplify.reports.retry"),
          click: () => {
            this.retryJob(row);
          },
        });
        options.push("-");
        options.push({
          icon: "trash",
          text: this.$t("simplify.reports.delete"),
          click: () => {
            this.deleteReportEntry(row.timestampRaw);
          },
        });
      }

      // Success Report: Preview, Delete entry
      if (isCompleted && row.itemType === "report") {
        options.push({
          icon: "open",
          text: this.$t("open"),
          click: () => {
            window.open(`/${this.variantCode}/${row.pageId}`, "_blank");
          },
        });
        options.push("-");
        options.push({
          icon: "trash",
          text: this.$t("simplify.reports.delete"),
          click: () => {
            this.deleteReportEntry(row.timestampRaw);
          },
        });
      }

      return options;
    },
    async translatePage(row) {
      const page = this.allPages?.find((p) => p.id === row.pageId);

      if (!page) {
        this.$panel.notification.error(
          this.$t("simplify.reports.retry.pageNotFound", {
            page: row.pageName,
          })
        );
        return;
      }

      // Call parent's translate method
      if (this.$parent.$parent.translateSinglePage) {
        await this.$parent.$parent.translateSinglePage(page);
      }
    },
    async cancelJob(jobId) {
      this.$panel.dialog.open({
        component: "k-remove-dialog",
        props: {
          text: this.$t("simplify.jobs.cancel.confirm"),
          submitButton: this.$t("cancel"),
          cancelButton: this.$t("continue"),
          icon: "cancel",
        },
        on: {
          submit: async () => {
            try {
              const response = await this.$api.post("simplify/job/cancel", {
                jobId: jobId,
              });

              if (response.success) {
                await this.loadJobs();
                await this.loadReports();
              } else {
                this.$panel.notification.error(
                  response.message || this.$t("simplify.jobs.cancel.error")
                );
              }
            } catch (error) {
              this.$panel.notification.error(
                this.$t("simplify.jobs.cancel.error") + ": " + error.message
              );
            }
            this.$panel.dialog.close();
          },
          cancel: () => {
            this.$panel.dialog.close();
          },
        },
      });
    },
    async retryJob(row) {
      // Find the page by ID or UUID
      let page = null;
      if (row.pageUuid) {
        page = this.allPages?.find((p) => p.uuid === row.pageUuid);
      }
      if (!page && row.pageId) {
        page = this.allPages?.find((p) => p.id === row.pageId);
      }
      if (!page && row.pageName) {
        page = this.allPages?.find((p) => p.title === row.pageName);
      }

      if (!page) {
        this.$panel.notification.error(
          this.$t("simplify.reports.retry.pageNotFound", {
            page: row.pageName || row.pageId,
          })
        );
        return;
      }

      // Translate the page via API (same as translateSinglePage in variant.vue)
      try {
        const response = await this.$api.post("simplify/page/translate", {
          variantCode: this.variantCode,
          pageId: page.id,
        });

        if (response.success) {
          if (response.status === "already-running") {
            this.$panel.notification.info(
              this.$t("simplify.pages.translateRunning", {
                page: page.rawTitle || page.title,
              })
            );
          } else {
            // Reload reports and jobs after translation starts
            await this.loadJobs();
            await this.loadReports();
          }
        } else {
          this.$panel.notification.error(
            response.message || this.$t("simplify.pages.translateError")
          );
        }
      } catch (error) {
        this.$panel.notification.error(
          this.$t("simplify.pages.translateError") +
            ": " +
            (error.message || error)
        );
      }
    },

    async deleteReportEntry(timestampRaw) {
      // Validate timestampRaw
      if (!timestampRaw) {
        this.$panel.notification.error(this.$t("simplify.reports.deleteError"));
        return;
      }

      // Show confirmation dialog
      this.$panel.dialog.open({
        component: "k-remove-dialog",
        props: {
          text: this.$t("simplify.reports.deleteConfirm"),
          submitButton: this.$t("delete"),
          cancelButton: this.$t("cancel"),
          icon: "trash",
        },
        on: {
          submit: async () => {
            try {
              const response = await this.$api.post("simplify/reports/delete", {
                variantCode: this.variantCode,
                timestamp: timestampRaw,
              });

              if (response.success) {
                // Remove entry from local reports array
                this.reports = this.reports.filter(
                  (r) => r.timestampRaw !== timestampRaw
                );
                this.$panel.dialog.close();
              } else {
                this.$panel.notification.error(
                  response.message || this.$t("simplify.reports.deleteError")
                );
                this.$panel.dialog.close();
              }
            } catch (error) {
              this.$panel.notification.error(
                this.$t("simplify.reports.deleteError")
              );
              this.$panel.dialog.close();
            }
          },
        },
      });
    },
    escapeHtml(text) {
      const map = {
        "&": "&amp;",
        "<": "&lt;",
        ">": "&gt;",
        '"': "&quot;",
        "'": "&#039;",
      };
      return text.replace(/[&<>"']/g, (m) => map[m]);
    },
    confirmClearReports() {
      this.$panel.dialog.open({
        component: "k-remove-dialog",
        props: {
          text: this.$t("simplify.reports.clearConfirm"),
          submitButton: this.$t("simplify.reports.clear"),
          cancelButton: this.$t("cancel"),
          icon: "trash",
        },
        on: {
          submit: async () => {
            await this.clearReports();
            this.$panel.dialog.close();
          },
        },
      });
    },
    async clearReports() {
      try {
        const response = await this.$api.post("simplify/reports/clear", {
          variantCode: this.variantCode,
        });

        if (response.success) {
          this.reports = [];
          this.$emit("reports-cleared");
        }
      } catch (error) {
        this.$panel.notification.error(this.$t("simplify.reports.clearError"));
      }
    },
  },
};
</script>

<style scoped>
.k-field-header > .k-field-label {
  cursor: default;
}

.simplify-reports-tab {
  display: flex;
  flex-direction: column;
  gap: var(--spacing-6);

  td {
    vertical-align: middle;
  }

  .k-table-index {
    padding: var(--table-cell-padding) 0;
  }
}
.report-error {
  font-size: var(--text-xs);
  color: var(--color-text-dimmed);
  line-height: 1.4em;
  padding: var(--spacing-1) 0;
}
</style>
