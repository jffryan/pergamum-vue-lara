const validateString = (value) => {
    return typeof value === "string" && value.length > 0;
};

// An author needs a first name or a last name, not both. Mononyms and
// organizations — Plato, Aristotle, National Geographic — have one name, and
// it goes in `first_name`. This mirrors
// `App\Http\Requests\Concerns\ValidatesAuthorNames` on the server; the two
// have to agree, or the form either blocks a payload the API would take or
// waves through one it 422s. Trimmed for the same reason the request
// normalizes before its rules run: a space is not a name.
const validateAuthor = (value) => {
    const named = (name) =>
        validateString(typeof name === "string" ? name.trim() : name);

    return named(value.first_name) || named(value.last_name);
};

const validateNumber = (value) => {
    return typeof value === "number";
};

// A version's length field — page count or audio runtime — is present.
// Both are `type="text"` inputs whose keystroke handler strips non-digits, so
// a filled one holds a digit *string*; an edit form loading from the API holds
// a number. Either is a value. Named once because checking one of the two with
// `validateNumber` alone (as the create form did for audio runtime) rejects
// every audiobook, and the asymmetry is invisible at the call site.
const validateVersionLength = (value) => {
    return validateString(value) || validateNumber(value);
};

export {
    validateString,
    validateAuthor,
    validateNumber,
    validateVersionLength,
};
