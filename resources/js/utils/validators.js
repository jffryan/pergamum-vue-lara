const validateString = (value) => {
    return typeof value === "string" && value.length > 0;
};

const validateAuthor = (value) => {
    return validateString(value.last_name);
};

const validateNumber = (value) => {
    return typeof value === "number";
};

export { validateString, validateAuthor, validateNumber };
