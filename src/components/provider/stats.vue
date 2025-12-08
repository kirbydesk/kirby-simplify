<template>
  <div class="k-provider-stats">
    <div class="k-field">
      <header class="k-field-header">
        <label class="k-label k-field-label">
          <span class="k-label-text">{{
            $t("simplify.provider.stats.box.headline")
          }}</span>
        </label>
        <div style="display: flex; gap: var(--spacing-2); align-items: center">
          <k-button-group layout="collapsed">
            <k-button
              dropdown
              :text="currentPeriodLabel"
              icon="calendar"
              size="sm"
              variant="filled"
              @click="$refs.periodDropdown.toggle()"
            />
            <k-dropdown-content
              ref="periodDropdown"
              :options="periodDropdownOptions"
              align-x="end"
            />
            <k-button
              icon="angle-left"
              size="sm"
              variant="filled"
              :disabled="!canNavigatePrev"
              @click="navigatePeriod('prev')"
            />
            <k-button
              icon="angle-right"
              size="sm"
              variant="filled"
              :disabled="!canNavigateNext"
              @click="navigatePeriod('next')"
            />
          </k-button-group>
        </div>
      </header>
      <dl
        v-if="stats && stats.length"
        data-size="large"
        class="k-stats"
        style="margin-bottom: 2.5rem"
      >
        <div
          class="k-stat"
          v-for="stat in stats"
          :key="stat.label"
          :data-theme="stat.theme"
        >
          <dt class="k-stat-label">
            <svg aria-hidden="true" :data-type="stat.icon" class="k-icon">
              <use :xlink:href="'#icon-' + stat.icon"></use>
            </svg>
            {{ stat.label }}
          </dt>
          <dd class="k-stat-value">{{ stat.value }}</dd>
          <dd class="k-stat-info">{{ stat.info }}</dd>
        </div>
      </dl>
    </div>

    <k-text v-if="statsError" style="margin-top: 2rem; color: var(--color-red)">
      {{ statsError }}
    </k-text>

    <!-- Recent Calls Table -->
    <div class="k-field" style="margin-top: 2rem">
      <header
        v-if="
          rawStats && rawStats.recent_calls && rawStats.recent_calls.length > 0
        "
        class="k-field-header"
      >
        <label class="k-label k-field-label">
          <span class="k-label-text">{{
            $t("simplify.provider.stats.recentCalls.label", {
              count: filteredCallsRows.length,
              period: currentPeriodLabel,
            })
          }}</span>
        </label>
        <div style="display: flex; gap: var(--spacing-2); align-items: center">
          <k-input
            v-if="statsSearching"
            ref="statsSearch"
            v-model="statsSearchQuery"
            :placeholder="$t('search') + ' …'"
            type="text"
            class="k-models-section-search k-input stats-search-input"
            style="
              min-height: auto;
              height: var(--height-sm);
              margin-bottom: 0;
              width: 200px;
            "
            @keydown.esc="toggleStatsSearch(true)"
          />
          <k-button
            :icon="statsSearching ? 'cancel' : 'filter'"
            size="sm"
            variant="filled"
            @click="toggleStatsSearch()"
          />
          <k-button
            icon="cog"
            size="sm"
            variant="filled"
            @click="$refs.settingsDropdown.toggle()"
          />
          <k-dropdown-content
            ref="settingsDropdown"
            :options="settingsDropdownOptions"
            align-x="end"
          />
        </div>
      </header>

      <k-table
        v-if="filteredCallsRows.length > 0"
        :index="paginationIndex"
        @header="onTableHeader"
        :columns="statsTableColumns"
        :rows="paginatedCallsRows"
      >
        <template #header="{ columnIndex, label }">
          <span class="k-table-header-sortable">
            {{ label }}
            <k-icon
              v-if="columnIndex === sortBy"
              :type="sortDirection === 'asc' ? 'angle-up' : 'angle-down'"
            />
          </span>
        </template>
      </k-table>
      <k-empty v-else icon="apicall" layout="cards">
        {{
          statsSearchQuery
            ? $t("search") + ": " + $t("search.results.none")
            : $t("simplify.provider.stats.box.apiCalls.empty")
        }}
      </k-empty>

      <footer v-if="filteredCallsRows.length > 0">
        <k-pagination
          :details="true"
          :total="filteredCallsRows.length"
          :page="pagination.page"
          :limit="pagination.limit"
          @paginate="handlePaginate"
        />
      </footer>
    </div>
  </div>
</template>

<script>
import costFormatting from "../../mixins/costFormatting.js";

export default {
  name: "StatsTab",
  mixins: [costFormatting],
  props: {
    provider: {
      type: Object,
      required: true,
    },
    providerId: {
      type: String,
      required: true,
    },
  },
  data() {
    return {
      stats: null,
      rawStats: null,
      statsLoading: false,
      statsError: null,

      // Period selector
      selectedPeriod: "month",
      customFrom: null,
      customTo: null,
      currentOffset: 0,

      // Pagination
      pagination: {
        page: 1,
        limit: 50,
      },

      // Sorting
      sortBy: "timestamp",
      sortDirection: "desc",

      // Stats search
      statsSearching: false,
      statsSearchQuery: "",
    };
  },
  computed: {
    statsTableColumns() {
      return {
        page: {
          label: this.$t("simplify.provider.stats.table.page"),
          type: "html",
          width: "1fr",
          mobile: true,
        },
        timestamp: {
          label: this.$t("simplify.provider.stats.table.timestamp"),
          type: "text",
          width: "175px",
          mobile: false,
        },
        model: {
          label: this.$t("simplify.provider.stats.table.model"),
          type: "html",
          width: "150px",
          mobile: false,
        },
        input_tokens: {
          label: this.$t("simplify.provider.stats.table.inputTokens"),
          type: "text",
          align: "right",
          width: "80px",
          mobile: true,
        },
        output_tokens: {
          label: this.$t("simplify.provider.stats.table.outputTokens"),
          type: "text",
          align: "right",
          width: "80px",
          mobile: true,
        },
        total_tokens: {
          label: this.$t("simplify.provider.stats.table.totalTokens"),
          type: "text",
          align: "right",
          width: "80px",
          mobile: true,
        },
        cost: {
          label: this.$t("simplify.provider.stats.table.cost"),
          type: "text",

          width: "110px",
          mobile: false,
        },
      };
    },
    paginationIndex() {
      return (this.pagination.page - 1) * this.pagination.limit + 1;
    },
    filteredCallsRows() {
      if (!this.statsSearchQuery) {
        return this.recentCallsRows;
      }

      const query = this.statsSearchQuery.toLowerCase();
      return this.recentCallsRows.filter((row) => {
        const searchableText =
          `${row.page} ${row.language} ${row.timestamp}`.toLowerCase();
        return searchableText.includes(query);
      });
    },
    sortedCallsRows() {
      if (!this.sortBy) {
        return this.filteredCallsRows;
      }

      const sorted = [...this.filteredCallsRows].sort((a, b) => {
        let aVal = a[this.sortBy];
        let bVal = b[this.sortBy];

        if (!isNaN(aVal) && !isNaN(bVal)) {
          aVal = parseFloat(aVal.toString().replace(/\./g, ""));
          bVal = parseFloat(bVal.toString().replace(/\./g, ""));
        }

        if (this.sortDirection === "asc") {
          return aVal > bVal ? 1 : aVal < bVal ? -1 : 0;
        } else {
          return aVal < bVal ? 1 : aVal > bVal ? -1 : 0;
        }
      });

      return sorted;
    },
    paginatedCallsRows() {
      const start = this.paginationIndex - 1;
      const end = this.pagination.limit * this.pagination.page;
      return this.sortedCallsRows.slice(start, end);
    },
    currentPeriodLabel() {
      const { from, to } = this.getCurrentPeriodDates();

      switch (this.selectedPeriod) {
        case "day":
          return from.toLocaleDateString(this.$panel.translation.code, {
            day: "2-digit",
            month: "long",
            year: "numeric",
          });
        case "month":
          return from.toLocaleDateString(this.$panel.translation.code, {
            month: "long",
            year: "numeric",
          });
        case "year":
          return from.toLocaleDateString(this.$panel.translation.code, {
            year: "numeric",
          });
        case "custom":
          if (this.customFrom && this.customTo) {
            return `${from.toLocaleDateString(this.$panel.translation.code, {
              day: "2-digit",
              month: "2-digit",
              year: "numeric",
            })} - ${to.toLocaleDateString(this.$panel.translation.code, {
              day: "2-digit",
              month: "2-digit",
              year: "numeric",
            })}`;
          }
          return this.$t("simplify.provider.stats.period.custom");
        case "all":
        default:
          return this.$t("simplify.provider.stats.period.all");
      }
    },
    periodDropdownOptions() {
      const units = ["all", "year", "month", "day"];
      const options = units.map((unit) => ({
        text:
          unit === "all"
            ? this.$t("simplify.provider.stats.period.all")
            : this.$t(unit),
        icon: this.isPeriodSelected(unit) ? "circle-filled" : "circle",
        current: this.isPeriodSelected(unit),
        click: () => this.selectPeriod(unit),
      }));

      options.push("-");

      options.push({
        text: this.$t("today"),
        icon: "merge",
        click: () => this.jumpToToday(),
      });

      options.push({
        text: this.$t("simplify.provider.stats.period.custom"),
        icon: "calendar",
        click: () => this.openCustomDateDialog(),
      });

      return options;
    },
    settingsDropdownOptions() {
      return [
        {
          text: this.$t("simplify.provider.stats.reset"),
          icon: "trash",
          click: () => this.confirmResetStats(),
        },
      ];
    },
    canNavigatePrev() {
      return this.selectedPeriod !== "all" && this.selectedPeriod !== "custom";
    },
    canNavigateNext() {
      if (this.selectedPeriod === "all" || this.selectedPeriod === "custom")
        return false;

      const now = new Date();
      const testOffset = this.currentOffset;

      this.currentOffset = testOffset + 1;
      const { from: nextFrom } = this.getCurrentPeriodDates();
      this.currentOffset = testOffset;

      return nextFrom <= now;
    },
    recentCallsRows() {
      if (!this.rawStats || !this.rawStats.recent_calls) {
        return [];
      }

      return this.rawStats.recent_calls.map((call) => {
        const date = new Date(call.timestamp * 1000);

        let pageDisplay = "-";
        if (call.page_title && call.page_id) {
          const pageIdEncoded = call.page_id.replace(/\//g, "+");
          const url = `pages/${pageIdEncoded}?language=${call.language_code}`;
          const languageSuffix = call.language_code
            ? `<span style="color: var(--color-gray-600); white-space: nowrap; font-style: italic; display: block;">${call.language_code}</span>`
            : "";
          pageDisplay = `<div style="display: block;"><a href="${url}" style="color: var(--color-blue-600); text-decoration: underline; display: block; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">${call.page_title}</a>${languageSuffix}</div>`;
        } else if (call.page_id) {
          const pageIdEncoded = call.page_id.replace(/\//g, "+");
          const url = `pages/${pageIdEncoded}?language=${call.language_code}`;
          const languageSuffix = call.language_code
            ? `<span style="color: var(--color-gray-600); white-space: nowrap; font-style: italic; display: block;">${call.language_code}</span>`
            : "";
          pageDisplay = `<div style="display: block;"><a href="${url}" style="color: var(--color-blue-600); text-decoration: underline; display: block; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">${call.page_id}</a>${languageSuffix}</div>`;
        } else if (call.context) {
          const contextKey = `simplify.provider.stats.context.${call.context}`;
          pageDisplay = this.$t(contextKey);
        }

        const inputTokens = call.input_tokens || 0;
        const outputTokens = call.output_tokens || 0;
        const totalTokens = inputTokens + outputTokens;
        const cost = call.cost; // Keep null as null, don't default to 0

        return {
          timestamp: date.toLocaleString(this.$panel.translation.code, {
            day: "2-digit",
            month: "2-digit",
            year: "numeric",
            hour: "2-digit",
            minute: "2-digit",
            second: "2-digit",
          }),
          page: pageDisplay,
          model: call.model
            ? `<span title="${call.model}">${call.model}</span>`
            : "-",
          cost: `${this.formatCostValue(cost !== null ? cost : 0)} ${
            this.provider?.provider_currency || "USD"
          }`,
          input_tokens: this.formatNumber(inputTokens),
          output_tokens: this.formatNumber(outputTokens),
          total_tokens: this.formatNumber(totalTokens),
        };
      });
    },
  },
  async mounted() {
    await this.loadStats();
  },
  methods: {
    toggleStatsSearch(closeOnly = false) {
      if (closeOnly) {
        this.statsSearching = false;
        this.statsSearchQuery = "";
        return;
      }

      this.statsSearching = !this.statsSearching;

      if (!this.statsSearching) {
        this.statsSearchQuery = "";
      } else {
        this.$nextTick(() => {
          this.$refs.statsSearch?.focus();
        });
      }
    },
    async loadStats() {
      this.statsLoading = true;
      this.statsError = null;

      const { from, to } = this.getCurrentPeriodDates();

      try {
        const params = {
          period: this.selectedPeriod,
        };

        if (this.currentOffset !== 0 || this.selectedPeriod === "custom") {
          params.from = Math.floor(from.getTime() / 1000);
          params.to = Math.floor(to.getTime() / 1000);
        }

        const response = await this.$api.get(
          `simplify/providers/${this.providerId}/stats`,
          params
        );

        if (response.success) {
          this.stats = response.stats;
          this.rawStats = response.rawStats;
        } else {
          this.statsError =
            response.message || this.$t("simplify.provider.stats.error");
        }
      } catch (error) {
        console.error("Failed to load stats:", error);
        this.statsError = this.$t("simplify.provider.stats.error");
      } finally {
        this.statsLoading = false;
      }
    },
    isPeriodSelected(unit) {
      return this.selectedPeriod === unit;
    },
    selectPeriod(unit) {
      this.selectedPeriod = unit;
      this.currentOffset = 0;
      this.customFrom = null;
      this.customTo = null;
      this.loadStats();
    },
    jumpToToday() {
      this.selectedPeriod = "day";
      this.currentOffset = 0;
      this.customFrom = null;
      this.customTo = null;
      this.loadStats();
    },
    openCustomDateDialog() {
      this.$panel.dialog.open({
        component: "k-form-dialog",
        props: {
          size: "small",
          fields: {
            from: {
              label: this.$t("from"),
              type: "date",
              time: false,
              required: true,
            },
            to: {
              label: this.$t("to"),
              type: "date",
              time: false,
              required: true,
            },
          },
          submitButton: this.$t("apply"),
          value: {
            from: this.customFrom || new Date().toISOString().split("T")[0],
            to: this.customTo || new Date().toISOString().split("T")[0],
          },
        },
        on: {
          submit: (value) => {
            this.selectedPeriod = "custom";
            this.customFrom = value.from;
            this.customTo = value.to;
            this.currentOffset = 0;
            this.loadStats();
            this.$panel.dialog.close();
          },
        },
      });
    },
    getCurrentPeriodDates() {
      const now = new Date();
      let from, to;

      switch (this.selectedPeriod) {
        case "day":
          from = new Date(now.getFullYear(), now.getMonth(), now.getDate());
          from.setDate(from.getDate() + this.currentOffset);
          to = new Date(
            from.getFullYear(),
            from.getMonth(),
            from.getDate(),
            23,
            59,
            59
          );
          break;
        case "month":
          from = new Date(now.getFullYear(), now.getMonth(), 1);
          from.setMonth(from.getMonth() + this.currentOffset);
          to = new Date(from.getFullYear(), from.getMonth() + 1, 0, 23, 59, 59);
          break;
        case "year":
          from = new Date(now.getFullYear(), 0, 1);
          from.setFullYear(from.getFullYear() + this.currentOffset);
          to = new Date(from.getFullYear(), 11, 31, 23, 59, 59);
          break;
        case "custom":
          if (this.customFrom && this.customTo) {
            from = new Date(this.customFrom);
            to = new Date(this.customTo);
            to.setHours(23, 59, 59, 999);
          } else {
            from = new Date();
            to = new Date();
          }
          break;
        default:
          from = new Date(0);
          to = new Date();
      }

      return { from, to };
    },
    navigatePeriod(direction) {
      if (this.selectedPeriod === "all") return;

      const multiplier = direction === "prev" ? -1 : 1;
      const newOffset = this.currentOffset + multiplier;

      const testOffset = this.currentOffset;
      this.currentOffset = newOffset;
      const { from } = this.getCurrentPeriodDates();

      if (direction === "next" && from > new Date()) {
        this.currentOffset = testOffset;
        return;
      }

      this.loadStats();
    },
    async confirmResetStats() {
      this.$panel.dialog.open({
        component: "k-remove-dialog",
        props: {
          text: this.$t("simplify.provider.stats.reset.confirm"),
          submitButton: this.$t("simplify.provider.stats.reset.button"),
          cancelButton: this.$t("cancel"),
        },
        on: {
          submit: async () => {
            try {
              const response = await this.$api.post(
                `simplify/providers/${this.providerId}/stats/reset`
              );

              if (response.success) {
                await this.loadStats();
              } else {
                this.$panel.notification.error(
                  response.message ||
                    this.$t("simplify.provider.stats.reset.error")
                );
              }
            } catch (error) {
              console.error("Failed to reset stats:", error);
              this.$panel.notification.error(
                this.$t("simplify.provider.stats.reset.error")
              );
            }
            this.$panel.dialog.close();
          },
        },
      });
    },
    handlePaginate(event) {
      this.pagination.page = event.page;
    },
    onTableHeader({ columnIndex }) {
      if (this.sortBy === columnIndex) {
        // Toggle between asc and desc
        this.sortDirection = this.sortDirection === "asc" ? "desc" : "asc";
      } else {
        this.sortBy = columnIndex;
        this.sortDirection = "desc";
      }

      this.pagination.page = 1;
    },
  },
};
</script>

<style>
.k-table-header-sortable {
  display: inline-flex;
  width: 100%;
  align-items: center;
  justify-content: space-between;
}

.k-table-header-sortable .k-icon {
  flex-shrink: 0;
}

.k-provider-stats .k-table .k-table-cell[data-align="right"] {
  font-weight: var(--font-normal);
  font-family: var(--code-font-family);
}

.k-provider-stats .k-table th[data-align="right"] .k-table-header-sortable {
  justify-content: flex-end;
}
</style>
