import { defineConfig } from "deepsec/config";

export default defineConfig({
  projects: [
    { id: "toeic", root: ".." },
    // <deepsec:projects-insert-above>
  ],
});
