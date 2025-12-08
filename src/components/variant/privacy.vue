<template>
  <k-grid variant="fields">
    <k-column width="2/3">
      <header class="k-field-header">
        <label class="k-label k-field-label">
          <span class="k-label-text">
            {{ $t("simplify.privacy.fieldtypes.label") }}
          </span>
        </label>
        <div style="display: flex; gap: var(--spacing-2); align-items: center">
          <button
            v-if="hasCustomFieldInstructions"
            data-has-icon="true"
            data-size="xs"
            data-variant="filled"
            type="button"
            class="k-button"
            :title="$t('simplify.instructions.fields.reset')"
            @click="$emit('reset-fields')"
          >
            <span class="k-button-icon">
              <svg aria-hidden="true" data-type="undo" class="k-icon">
                <use xlink:href="#icon-undo"></use>
              </svg>
            </span>
          </button>
        </div>
      </header>

      <k-fieldset
        :fields="optOutFieldTypesOnly"
        v-model="invertedFieldTypesData"
      />

      <div style="margin-top: 2.5rem">
        <k-fieldset :fields="fieldsFields" v-model="localFieldsData" />
      </div>
    </k-column>

    <k-column width="1/3">
      <header class="k-field-header">
        <label class="k-label k-field-label">
          <span class="k-label-text">
            {{ $t("simplify.privacy.optout.fields.label") }}
          </span>
        </label>
        <div style="display: flex; gap: var(--spacing-2); align-items: center">
          <button
            v-if="hasCustomOptOut"
            data-has-icon="true"
            data-size="xs"
            data-variant="filled"
            type="button"
            class="k-button"
            :title="$t('simplify.privacy.fields.reset')"
            @click="$emit('reset-optout')"
          >
            <span class="k-button-icon">
              <svg aria-hidden="true" data-type="undo" class="k-icon">
                <use xlink:href="#icon-undo"></use>
              </svg>
            </span>
          </button>
        </div>
      </header>

      <k-fieldset :fields="optOutFieldsOnly" v-model="localOptOutData" />

      <div style="margin-top: 2.5rem">
        <k-fieldset :fields="maskingFields" v-model="localMaskingData" />
      </div>
    </k-column>
  </k-grid>
</template>

<script>
export default {
  name: "PrivacyTab",
  props: {
    optOutFields: {
      type: Object,
      required: true,
    },
    optOutValue: {
      type: Object,
      default: () => ({}),
    },
    fieldsFields: {
      type: Object,
      required: true,
    },
    fieldsValue: {
      type: Object,
      default: () => ({}),
    },
    hasCustomOptOut: {
      type: Boolean,
      default: false,
    },
    hasCustomFieldInstructions: {
      type: Boolean,
      default: false,
    },
    maskingFields: {
      type: Object,
      required: true,
    },
    maskingValue: {
      type: Object,
      default: () => ({}),
    },
  },
  computed: {
    optOutFieldsOnly() {
      return {
        opt_out_fields: this.optOutFields.opt_out_fields,
      };
    },
    optOutFieldTypesOnly() {
      return {
        opt_out_fieldtypes: this.optOutFields.opt_out_fieldtypes,
      };
    },
    localOptOutData: {
      get() {
        return this.optOutValue;
      },
      set(val) {
        this.$emit("update:optOutValue", val);
      },
    },
    invertedFieldTypesData: {
      get() {
        // Get all available field type options
        const allFieldTypes =
          this.optOutFields.opt_out_fieldtypes?.options || [];
        const allFieldTypeValues = allFieldTypes.map((opt) => opt.value);

        // Get currently excluded field types
        const excludedFieldTypes = this.optOutValue.opt_out_fieldtypes || [];

        // Invert: return the ones that are NOT excluded (= enabled/checked)
        const enabledFieldTypes = allFieldTypeValues.filter(
          (type) => !excludedFieldTypes.includes(type)
        );

        return {
          opt_out_fieldtypes: enabledFieldTypes,
        };
      },
      set(val) {
        // Get all available field type options
        const allFieldTypes =
          this.optOutFields.opt_out_fieldtypes?.options || [];
        const allFieldTypeValues = allFieldTypes.map((opt) => opt.value);

        // val.opt_out_fieldtypes contains the ENABLED (checked) field types
        const enabledFieldTypes = val.opt_out_fieldtypes || [];

        // Invert: calculate which ones are DISABLED (unchecked)
        const disabledFieldTypes = allFieldTypeValues.filter(
          (type) => !enabledFieldTypes.includes(type)
        );

        // Emit the disabled ones as opt_out_fieldtypes
        this.$emit("update:optOutValue", {
          ...this.optOutValue,
          opt_out_fieldtypes: disabledFieldTypes,
        });
      },
    },
    localFieldsData: {
      get() {
        return this.fieldsValue;
      },
      set(val) {
        this.$emit("update:fieldsValue", val);
      },
    },
    localMaskingData: {
      get() {
        return this.maskingValue;
      },
      set(val) {
        this.$emit("update:maskingValue", val);
      },
    },
  },
};
</script>
