<template>
  <form
    @submit.prevent="submitReadInstance"
    class="p-4 mb-4 bg-white border rounded-md border-zinc-400 shadow-md"
  >
    <div>
      <div class="mb-4">
        <label for="date_read" class="block mb-2 font-bold text-zinc-600 mr-6"
          >Date Completed</label
        >
        <input
          type="text"
          id="date_read"
          name="date_read"
          :value="readInstance.date_read"
          @input="updateDateCompleted"
          placeholder="MM/DD/YYYY"
          class="border-2 border-t-transparent border-x-transparent border-b-zinc-400 p-2 w-full mb-2 focus:border-2 focus:outline-none focus:border-zinc-600 focus:rounded-md transition-all"
        />
      </div>
      <!-- END DATE COMPLETED -->
      <div class="mb-4">
        <label for="rating" class="block mb-2 font-bold text-zinc-600 mr-6"
          >Rating</label
        >
        <select
          v-model="readInstance.rating"
          class="bg-zinc-100 text-zinc-700 border rounded p-2 focus:border-zinc-500 focus:outline-none"
        >
          <option value="" class="text-zinc-400" disabled>
            Select a rating
          </option>
          <option
            v-for="(rating, idx) in Array.from(
              { length: 9 },
              (_, i) => 1 + i * 0.5
            )"
            :key="idx"
            :value="rating"
            class="text-zinc-700"
          >
            {{ rating }}
          </option>
        </select>
      </div>
      <!-- END RATING INPUT -->
      <div class="flex justify-end">
        <button class="btn btn-primary" type="submit">Submit</button>
      </div>
    </div>
  </form>
</template>

<script>
import axios from "axios";

import { useNewBookStore } from "@/stores";

export default {
  name: "UpdateReadInstanceInput",
  setup() {
    const NewBookStore = useNewBookStore();

    return {
      NewBookStore,
    };
  },
  data() {
    return {
      readInstance: {
        date_read: "",
        rating: "",
      },
    };
  },
  methods: {
    updateDateCompleted(event) {
      const { value } = event.target;
      const adjustedValue = this.addSlashes(value);
      // A regex pattern that allows partially entered valid dates
      const partialRegex =
        /^((0[1-9]|1[0-2])\/?)?((0[1-9]|[12][0-9]|3[01])\/?)?((19|20)?\d{0,2})?$/;

      if (partialRegex.test(adjustedValue)) {
        this.readInstance.date_read = adjustedValue;
      } else {
        // Try to rewrite this to fix the lint issue
        event.target.value = this.readInstance.date_read;
      }
    },
    addSlashes(value) {
      if (value.length === 2 || value.length === 5) {
        return `${value}/`;
      }
      return value;
    },
    async submitReadInstance() {
      await axios.post("/api/add-read-instance", {
        book_id: this.NewBookStore.currentBook.id,
        date_read: this.readInstance.date_read,
        rating: this.readInstance.rating,
      });
    },
  },
};
</script>
